<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Sync\LedgerSyncInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls a receiving account's incoming Stellar payments into storage,
 * deduplicated, and decides which of the payments on a memo id fulfil an
 * intent — the sibling of Xrpl\SyncService with the same semantics
 * (INVARIANTS.md, "Stellar" and "Settlement").
 */
final class SyncService implements LedgerSyncInterface
{
    private const MAX_PAGES = 1000;

    /**
     * How far a stored cursor may sit above Horizon's `history_latest_ledger`
     * before it counts as a reset. Horizon's root document can lag the
     * ledger it has just ingested by one or two — the M0 fixtures caught
     * exactly that — while a testnet reset rewinds by millions. A thousand
     * ledgers is about 80 minutes of lag: far more than ingestion ever
     * needs, far less than any epoch.
     */
    public const RESET_LEDGER_MARGIN = 1000;

    public function __construct(
        private readonly PaymentSourceInterface $source,
        private readonly StellarPaymentRepositoryInterface $paymentRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(string $account, string $network): void
    {
        $this->syncPayments($account, $network);
    }

    /**
     * Fetches the account's payments since the stored cursor and stores the
     * new ones, page by page.
     *
     * The cursor is checked against the source's newest ledger first:
     * Horizon answers a cursor from a previous testnet epoch with 200 and
     * nothing, so the reset has to be noticed here — the cursor's upper 32
     * bits are the ledger it came from, and a ledger more than
     * RESET_LEDGER_MARGIN above the newest one cannot be ours. Then a full
     * resync; the (hash, op_index) key keeps it from duplicating anything.
     */
    public function syncPayments(string $account, string $network): void
    {
        $cursor = $this->paymentRepository->getLastPaymentId($account, $network);

        if ($cursor !== null) {
            $cursorLedger = BigInteger::of($cursor)->shiftedRight(32)->toInt();
            $latestLedger = $this->source->latestLedger($network);

            if ($cursorLedger > $latestLedger + self::RESET_LEDGER_MARGIN) {
                $this->logger->warning('LedgerDirect: stored Stellar cursor is ahead of the network, resyncing without one', [
                    'account' => $account,
                    'network' => $network,
                    'cursor' => $cursor,
                    'cursor_ledger' => $cursorLedger,
                    'latest_ledger' => $latestLedger,
                ]);

                $cursor = null;
            }
        }

        $pages = 0;

        do {
            $page = $this->source->fetchPayments($account, $network, $cursor);

            if ($page['payments'] !== []) {
                $this->storeNew($page['payments']);
            }

            $cursor = $page['cursor'];
            $pages++;
        } while (!$page['exhausted'] && $pages < self::MAX_PAGES);
    }

    /**
     * Every stored payment on this account and memo id, newest first — to
     * *show* what arrived; use findFulfillmentFor() to decide what pays.
     *
     * @return list<StellarPayment>
     */
    public function findPayments(string $destinationAccount, string $memoId): array
    {
        return $this->paymentRepository->findPayments($destinationAccount, $memoId);
    }

    /**
     * Which payments on the intent's memo id pay for it, and what they
     * delivered in total — the Stellar reading of the settlement rule:
     * every payment in the quoted asset (XLM for a native quote; the quoted
     * code *and* issuer for an issued one) adds up. If nothing arrived in
     * the quoted asset but something did in another issued asset, the
     * newest such payment alone is the fulfillment, so the platform can
     * show wrong_asset. A class mismatch (an issued payment on an XLM
     * quote, or XLM on an issued quote) is skipped and logged.
     */
    public function findFulfillmentFor(PaymentIntent $intent): ?Fulfillment
    {
        $quotedIssued = is_array($intent->amountRequested);
        $quoted = [];
        $foreign = [];

        foreach ($this->findPayments($intent->destinationAccount, (string) $intent->destinationTag) as $payment) {
            $issued = $payment->assetIssuer !== null;

            if ($issued !== $quotedIssued) {
                $this->logger->warning('LedgerDirect: Stellar payment in a different asset class on this memo skipped', [
                    'hash' => $payment->hash,
                    'op_index' => $payment->opIndex,
                    'memo_id' => $payment->memoId,
                    'quoted_asset' => $intent->baseAsset,
                ]);

                continue;
            }

            $isQuoted = !$issued
                || ($payment->assetCode === $intent->amountRequested['currency']
                    && $payment->assetIssuer === $intent->amountRequested['issuer']);

            if ($isQuoted) {
                $quoted[] = $payment;
            } else {
                $foreign[] = $payment;
            }
        }

        if ($quoted !== []) {
            if (count($quoted) > 1) {
                $this->logger->info('LedgerDirect: several Stellar payments in the quoted asset on this memo add up', [
                    'memo_id' => $intent->destinationTag,
                    'count' => count($quoted),
                    'hashes' => array_map(static fn (StellarPayment $p): string => $p->hash, $quoted),
                ]);
            }

            foreach ($foreign as $payment) {
                $this->logger->warning('LedgerDirect: Stellar payment in another asset on this memo ignored, the order has payments in the quoted asset', [
                    'hash' => $payment->hash,
                    'op_index' => $payment->opIndex,
                    'memo_id' => $intent->destinationTag,
                    'quoted_asset' => $intent->baseAsset,
                ]);
            }

            return new Fulfillment($quoted, self::sum($intent, $quoted));
        }

        if ($foreign !== []) {
            return new Fulfillment([$foreign[0]], StellarAmount::decode($foreign[0]));
        }

        return null;
    }

    /**
     * @param list<StellarPayment> $payments
     */
    private function storeNew(array $payments): void
    {
        $keys = array_map(static fn (StellarPayment $p): string => $p->key(), $payments);
        $existing = $this->paymentRepository->findExistingKeys($keys);

        $new = array_values(array_filter(
            $payments,
            static fn (StellarPayment $p): bool => !in_array($p->key(), $existing, true),
        ));

        if ($new !== []) {
            $this->paymentRepository->savePayments($new);
        }
    }

    /**
     * @param list<StellarPayment> $payments
     * @return float|array{currency: string, value: string, issuer: string}
     */
    private static function sum(PaymentIntent $intent, array $payments): float|array
    {
        $total = BigDecimal::zero();

        foreach ($payments as $payment) {
            $total = $total->plus(BigDecimal::of($payment->amount));
        }

        if (is_array($intent->amountRequested)) {
            return [
                'currency' => $intent->amountRequested['currency'],
                'value' => PaymentIntent::plainDecimal($total),
                'issuer' => $intent->amountRequested['issuer'],
            ];
        }

        return $total->toFloat();
    }
}
