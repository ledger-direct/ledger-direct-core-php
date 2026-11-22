<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\PaymentOverview;
use Hardcastle\LedgerDirect\Core\Refund\ReceivedPayment;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The payments an order received, one by one, each with what can still go back from it.
 *
 * SyncService::findFulfillmentFor() answers "is the order paid" with one sum. A refund needs the
 * parts: it pays back one payment, to that payment's sender, in that payment's asset, and never
 * more than that payment delivered. Reads the synced transactions on the intent's account and
 * tag, on the intent's network only, and the refunds already filed for each.
 *
 * Three purposes, decided here by what the payment is (INVARIANTS.md, "Refund"):
 *
 * - a payment in **another asset** than the quoted one can only go back as it came
 *   (`wrong_asset_return`);
 * - a payment in the **quoted asset** can be refunded for the order (`order_refund`), up to
 *   what it still has to give;
 * - if more arrived than was asked, the excess can go back without touching the order
 *   (`surplus_return`), up to the excess.
 */
final class ReceivedPayments
{
    /** A transaction's `date` counts seconds from 2000-01-01; Unix time counts from 1970. */
    private const LEDGER_EPOCH_OFFSET = 946684800;

    public function __construct(
        private readonly XrplTransactionRepositoryInterface $transactions,
        private readonly RefundRepositoryInterface $refunds,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function of(PaymentIntent $intent): PaymentOverview
    {
        $requested = self::requested($intent);
        $zero = BigDecimal::zero();

        $rows = [];
        $received = $zero;
        $surplusClaimed = $zero;
        $orderRefunded = $zero;

        foreach ($this->transactions->findTransactions($intent->destinationAccount, $intent->destinationTag) as $transaction) {
            // The same account and tag exist on testnet and mainnet; test money is not the order's.
            if ($transaction->network !== $intent->network) {
                continue;
            }

            $delivered = $this->delivered($transaction);
            if ($delivered === null) {
                continue;
            }

            $quoted = $delivered->sameAsset($requested);
            $refunded = $zero;
            $pending = $zero;
            $refunds = [];

            foreach ($this->refunds->findBySource($intent->network, strtoupper($transaction->hash)) as $refund) {
                $refunds[] = $refund;
                $claimed = $refund->claimedAmount();
                if ($claimed === null) {
                    continue;
                }

                if (!$claimed->sameAsset($delivered)) {
                    // Cannot be created by the core; a row somebody edited. It claims nothing here.
                    $this->logger->warning('LedgerDirect: a refund in another asset than its source payment is ignored', [
                        'refund' => $refund->id,
                        'source_hash' => $refund->sourceHash,
                    ]);

                    continue;
                }

                $amount = BigDecimal::of($claimed->value);
                if ($refund->isExecuted()) {
                    $refunded = $refunded->plus($amount);
                } else {
                    $pending = $pending->plus($amount);
                }

                if ($quoted && $refund->purpose === Refund::PURPOSE_SURPLUS_RETURN) {
                    $surplusClaimed = $surplusClaimed->plus($amount);
                }
                if ($quoted && $refund->purpose === Refund::PURPOSE_ORDER_REFUND && $refund->isExecuted()) {
                    $orderRefunded = $orderRefunded->plus($amount);
                }
            }

            if ($quoted) {
                $received = $received->plus(BigDecimal::of($delivered->value));
            }

            $rows[] = [$transaction, $delivered, $quoted, $refunded, $pending, $refunds];
        }

        $asked = BigDecimal::of($requested->value);
        $credited = $received->isLessThan($asked) ? $received : $asked;
        $surplus = $received->minus($credited);
        $surplusReturnable = self::notBelowZero($surplus->minus($surplusClaimed));

        $payments = [];
        foreach ($rows as [$transaction, $delivered, $quoted, $refunded, $pending, $refunds]) {
            $refundable = self::notBelowZero(BigDecimal::of($delivered->value)->minus($refunded)->minus($pending));

            $limits = [];
            if (!$quoted) {
                $limits[Refund::PURPOSE_WRONG_ASSET_RETURN] = $refundable;
            } else {
                $limits[Refund::PURPOSE_ORDER_REFUND] = $refundable;
                $limits[Refund::PURPOSE_SURPLUS_RETURN] = $refundable->isLessThan($surplusReturnable) ? $refundable : $surplusReturnable;
            }

            $payments[] = new ReceivedPayment(
                hash: strtoupper($transaction->hash),
                ledgerIndex: (int) $transaction->ledgerIndex,
                paidAt: $transaction->date + self::LEDGER_EPOCH_OFFSET,
                sender: $transaction->account,
                delivered: $delivered,
                inQuotedAsset: $quoted,
                refunded: PaymentIntent::plainDecimal($refunded),
                pending: PaymentIntent::plainDecimal($pending),
                refundable: PaymentIntent::plainDecimal($refundable),
                limits: array_map(
                    PaymentIntent::plainDecimal(...),
                    array_filter($limits, static fn (BigDecimal $limit): bool => $limit->isPositive()),
                ),
                refunds: $refunds,
            );
        }

        return new PaymentOverview(
            requested: $requested,
            received: PaymentIntent::plainDecimal($received),
            credited: PaymentIntent::plainDecimal($credited),
            surplus: PaymentIntent::plainDecimal($surplus),
            surplusReturnable: PaymentIntent::plainDecimal($surplusReturnable),
            orderRefunded: PaymentIntent::plainDecimal($orderRefunded),
            orderRefundState: match (true) {
                $orderRefunded->isZero() => PaymentOverview::ORDER_REFUND_NONE,
                $orderRefunded->isGreaterThanOrEqualTo($credited) => PaymentOverview::ORDER_REFUND_FULL,
                default => PaymentOverview::ORDER_REFUND_PARTIAL,
            },
            payments: $payments,
        );
    }

    /** The quote's amount, exact: the record keeps a native amount as a float, a token as an object. */
    private static function requested(PaymentIntent $intent): LedgerAmount
    {
        if (is_array($intent->amountRequested)) {
            return LedgerAmount::issued(
                (string) $intent->amountRequested['currency'],
                (string) $intent->amountRequested['issuer'],
                $intent->amountRequestedValue(),
            );
        }

        return LedgerAmount::native($intent->amountRequestedValue());
    }

    /**
     * What the stored transaction delivered, exact, or null when it is not a payment that
     * delivered something readable (an EscrowCreate, a Remit, the ledger's `unavailable`).
     */
    private function delivered(XrplTransaction $transaction): ?LedgerAmount
    {
        $result = $transaction->meta['TransactionResult'] ?? LedgerTransaction::RESULT_SUCCESS;
        $wire = $transaction->meta['delivered_amount'] ?? $transaction->meta['DeliveredAmount'] ?? null;

        if ($result !== LedgerTransaction::RESULT_SUCCESS || $wire === null) {
            return null;
        }

        try {
            return LedgerAmount::fromWire($wire);
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning('LedgerDirect: a received transaction with an unreadable delivered amount is left out', [
                'hash' => $transaction->hash,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private static function notBelowZero(BigDecimal $value): BigDecimal
    {
        return $value->isNegative() ? BigDecimal::zero() : $value;
    }
}
