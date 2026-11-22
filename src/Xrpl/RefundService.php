<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\PaymentOverview;
use Hardcastle\LedgerDirect\Core\Refund\ReceivedPayment;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundAmount;
use Hardcastle\LedgerDirect\Core\Refund\RefundCancellation;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundNotice;
use Hardcastle\LedgerDirect\Core\Refund\RefundPreview;
use Hardcastle\LedgerDirect\Core\Refund\RefundRefusedException;
use Hardcastle\LedgerDirect\Core\Refund\RefundRequest;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Refunds on the XRP Ledger and on Xahau: says what a refund request would do (preview()),
 * creates the refund (create()) and withdraws it (cancel()).
 *
 * **LedgerDirect never sends.** A refund is a payment request; the merchant signs it in their
 * own wallet (paymentUri()). Nothing here holds a key, and nothing here calls a refund paid:
 * that is decided when the payment is found on the ledger.
 *
 * The rules a request has to pass are in INVARIANTS.md, "Refund". The one that matters most:
 * **no refund without a destination tag unless somebody vouches for the address.** A sender
 * that requires a tag, or whose payment carried a source tag, may be an account many customers
 * share, and is never paid back without a tag; anywhere else the merchant has to confirm that
 * the address is the customer's own wallet. The rule is the same whether the sender's address
 * was taken from the payment or typed in.
 */
final class RefundService
{
    /** What the reference of a refund is derived under; part of the derivation, never change it. */
    private const REFERENCE_DOMAIN = 'ledger-direct-refund-v1';

    /**
     * How often create() looks again when storage refuses the refund because another request
     * for the same payment got there first.
     */
    private const MAX_ATTEMPTS = 3;

    /** A token value keeps 16 significant digits on the ledger; more would not arrive as asked. */
    private const MAX_TOKEN_DIGITS = 16;

    /** Pages of the paying account's transactions a preview reads to look for an earlier payment. */
    private const PRIOR_PAYMENT_PAGES = 2;

    /** Earlier payments a preview lists at most. */
    private const PRIOR_PAYMENT_NOTICES = 5;

    /** A transaction's `date` counts seconds from 2000-01-01; Unix time counts from 1970. */
    private const LEDGER_EPOCH_OFFSET = 946684800;

    private readonly ReceivedPayments $receivedPayments;

    private readonly RefundDestinationCheck $destinationCheck;

    private readonly ClockInterface $clock;

    private readonly RefundReconciler $reconciler;

    /**
     * @param string $chain 'XRPL' or 'XAHAU', as the client and the repositories it is given
     * @param string|null $referenceKey a secret of the installation. With it a refund's
     *     reference can be derived again from the payment it refunds (deriveReference()), so
     *     executed refunds can be found on the ledger even if the refund table were lost;
     *     without it nobody outside can compute which payment belongs to which. Null: the
     *     reference is random.
     * @param RefundReconciler|null $reconciler reads the ledger before a refund is cancelled; the
     *     composition root passes the one it also hands to the scheduled task
     */
    public function __construct(
        private readonly XrplClient $client,
        XrplTransactionRepositoryInterface $transactions,
        private readonly RefundRepositoryInterface $refunds,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
        private readonly string $chain = ChainCatalog::XRPL,
        private readonly ?string $referenceKey = null,
        ?RefundReconciler $reconciler = null,
    ) {
        if (!in_array($chain, [ChainCatalog::XRPL, ChainCatalog::XAHAU], true)) {
            throw new InvalidArgumentException("Refunds exist on XRPL and XAHAU, not on '{$chain}'.");
        }

        $this->receivedPayments = new ReceivedPayments($transactions, $refunds, $logger);
        $this->destinationCheck = new RefundDestinationCheck($client);
        $this->clock = $clock ?? new SystemClock();
        $this->reconciler = $reconciler ?? new RefundReconciler($client, $refunds, $logger, $this->clock, null, $chain);
    }

    /**
     * The payments the order received, each with what can still go back from it.
     */
    public function overview(PaymentIntent $intent): PaymentOverview
    {
        $this->assertChain($intent);

        return $this->receivedPayments->of($intent);
    }

    /**
     * What the request would do, without storing anything. Asks the ledger about the payment
     * and the destination, so the answer is as of now.
     */
    public function preview(RefundRequest $request): RefundPreview
    {
        return $this->assess($request)['preview'];
    }

    /**
     * Creates the refund, awaiting execution.
     *
     * @throws RefundRefusedException with the reasons, when the request does not pass
     */
    public function create(RefundRequest $request): Refund
    {
        $network = $request->intent->network;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $assessment = $this->assess($request);
            $preview = $assessment['preview'];

            if (!$preview->isAllowed() || $assessment['payment'] === null || $preview->amount === null || $preview->destination === null) {
                throw new RefundRefusedException($preview->blockers());
            }

            try {
                $ledger = $this->client->validatedLedgerIndex($network);
            } catch (XrplRpcException $exception) {
                throw new RefundRefusedException([self::ledgerUnavailable($exception)]);
            }

            /*
             * The number is taken from the same reading the limit was computed from. If another
             * request for this payment was stored since, it has this number already, storage
             * refuses this one, and the next round reads again: the limit is then computed with
             * the other request in it.
             */
            $payment = $assessment['payment'];
            $serial = count($payment->refunds) + 1;

            $refund = Refund::request(
                id: bin2hex(random_bytes(16)),
                chain: $this->chain,
                network: $network,
                purpose: $request->purpose,
                platformReference: $request->platformReference,
                sourceHash: $payment->hash,
                serial: $serial,
                payingAccount: (string) $preview->payingAccount,
                destination: new RefundDestination(
                    account: $preview->destination,
                    tag: $preview->destinationTag,
                    origin: (string) $preview->destinationOrigin,
                    // From what the merchant said, not from the absence of a tag: without the
                    // confirmation a destination without a tag cannot be built at all.
                    attestation: $preview->destinationTag === null && $request->ownWalletConfirmed
                        ? RefundDestination::ATTESTATION_OWN_WALLET
                        : null,
                ),
                amount: $preview->amount,
                reference: $this->reference($network, $payment->hash, $serial),
                createdAt: $this->now(),
                createdLedger: $ledger,
                createdBy: trim((string) $request->createdBy),
                reason: $request->reason === null || trim($request->reason) === '' ? null : trim($request->reason),
                fiatAmount: $assessment['fiat'],
                fiatCurrency: $assessment['fiat'] === null ? null : $request->intent->quoteCurrency,
            );

            if ($this->refunds->insert($refund)) {
                $this->withdrawIfOverClaimed($refund, $request);

                $this->logger->info('LedgerDirect: refund created, awaiting execution', [
                    'refund' => $refund->id,
                    'purpose' => $refund->purpose,
                    'source_hash' => $refund->sourceHash,
                    'destination_origin' => $refund->destination->origin,
                    'with_tag' => $refund->destination->tag !== null,
                ]);

                return $refund;
            }
        }

        throw new RefundRefusedException([new RefundNotice(RefundNotice::CONFLICT, true)]);
    }

    /**
     * Withdraws a refund that still awaits execution — after reading the ledger once more. A
     * refund that was paid a moment ago comes back executed, not cancelled; and if the ledger
     * cannot be read to the end, the refund stays as it is: cancelling it would free its amount
     * for a second refund while nobody knows whether the first was paid.
     *
     * Whatever else the refund is by now — executed, already cancelled — comes back as it is.
     */
    public function cancel(string $id): RefundCancellation
    {
        $refund = $this->refunds->find($id);
        if ($refund === null || !$refund->isAwaitingExecution()) {
            return new RefundCancellation($refund);
        }

        $reconciled = $this->reconciler->reconcile($refund->payingAccount, $refund->network);

        $otherEpoch = false;
        foreach ($reconciled->unreachable as $unreachable) {
            $otherEpoch = $otherEpoch || $unreachable->id === $id;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $refund = $this->refunds->find($id);
            if ($refund === null || !$refund->isAwaitingExecution()) {
                return new RefundCancellation($refund, $reconciled);
            }

            // A refund from before a test network was reset cannot be paid on this ledger; it
            // can go without the look that is impossible for it.
            if (!$reconciled->complete && !$otherEpoch) {
                return new RefundCancellation($refund, $reconciled);
            }

            $cancelled = $refund->cancelled($this->now());
            if ($this->refunds->update($cancelled, $refund->version)) {
                $this->logger->info('LedgerDirect: refund cancelled', ['refund' => $refund->id]);

                return new RefundCancellation($cancelled, $reconciled);
            }
        }

        return new RefundCancellation($this->refunds->find($id), $reconciled);
    }

    /**
     * The merchant names the transaction that paid a refund: one the core did not recognise by
     * itself, because it came from another account of theirs, in another amount, before the
     * refund existed, or after it was cancelled. It must be a validated, successful payment to
     * the refund's destination, with the refund's tag, in the refund's asset. In what it
     * differs from the request is written on the refund (Refund::DEVIATIONS).
     *
     * @throws RefundRefusedException with the reason, when it is not such a payment
     */
    public function attach(string $id, string $hash): Refund
    {
        $hash = strtoupper(trim($hash));

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $refund = $this->refunds->find($id) ?? throw self::refusal(RefundNotice::REFUND_NOT_FOUND);
            if ($refund->isExecuted()) {
                throw self::refusal(RefundNotice::ALREADY_EXECUTED);
            }

            if (preg_match('/^[0-9A-F]{64}$/', $hash) !== 1) {
                throw self::refusal(RefundNotice::TRANSACTION_NOT_FOUND);
            }

            try {
                $result = $this->client->tx($hash, $refund->network);
                $transaction = $result === null ? null : LedgerTransaction::fromTx($result);
            } catch (XrplRpcException $exception) {
                throw new RefundRefusedException([self::ledgerUnavailable($exception)]);
            }

            $delivered = $transaction?->delivered;
            $mismatch = match (true) {
                $transaction === null => throw self::refusal(RefundNotice::TRANSACTION_NOT_FOUND),
                !$transaction->validated => throw self::refusal(RefundNotice::TRANSACTION_NOT_VALIDATED),
                !$transaction->succeeded() => throw self::refusal(RefundNotice::TRANSACTION_FAILED, ['result' => (string) $transaction->result]),
                !$transaction->isPayment() || $delivered === null => 'type',
                $transaction->destination !== $refund->destination->account => 'destination',
                $transaction->destinationTag !== $refund->destination->tag => 'tag',
                !$delivered->sameAsset($refund->amount) => 'asset',
                default => null,
            };
            if ($mismatch !== null || $delivered === null) {
                throw self::refusal(RefundNotice::TRANSACTION_MISMATCH, ['field' => (string) $mismatch]);
            }

            $executed = $refund->executed(
                hash: $transaction->hash,
                ledgerIndex: (int) $transaction->ledgerIndex,
                executedAt: $transaction->date === null ? $this->now() : $transaction->date + self::LEDGER_EPOCH_OFFSET,
                account: $transaction->account,
                delivered: $delivered,
                matchedBy: Refund::MATCHED_BY_ATTACHED,
                closedAt: $this->now(),
            );

            if ($this->refunds->update($executed, $refund->version)) {
                $this->logger->info('LedgerDirect: refund executed by a transaction the merchant named', [
                    'refund' => $refund->id,
                    'hash' => $executed->executedHash,
                    'deviation' => implode(',', $executed->deviations),
                ]);

                return $executed;
            }

            // Refused: the refund changed meanwhile (read again), or the transaction is taken.
            if ($this->refunds->find($id)?->version === $refund->version) {
                throw self::refusal(RefundNotice::TRANSACTION_ALREADY_ASSIGNED);
            }
        }

        throw self::refusal(RefundNotice::CONFLICT);
    }

    public function find(string $id): ?Refund
    {
        return $this->refunds->find($id);
    }

    /**
     * @return list<Refund> oldest first
     */
    public function findByPlatformReference(string $platformReference): array
    {
        return $this->refunds->findByPlatformReference($platformReference);
    }

    /**
     * The payment request the merchant signs: a link a wallet opens, and what a QR code encodes.
     * Null for a refund that no longer awaits execution: a cancelled or executed refund is not
     * offered for signing again.
     */
    public function paymentUri(Refund $refund): ?string
    {
        return $refund->isAwaitingExecution() ? PaymentUri::forRefund($refund) : null;
    }

    /**
     * A refund's reference, from the installation's key and the payment it refunds:
     * HMAC-SHA256 over `ledger-direct-refund-v1|chain|network|source hash|serial`, 64 hex
     * digits in upper case. Public so that a tool can recompute it.
     */
    public static function deriveReference(string $key, string $chain, string $network, string $sourceHash, int $serial): string
    {
        return strtoupper(hash_hmac(
            'sha256',
            implode('|', [self::REFERENCE_DOMAIN, $chain, $network, $sourceHash, (string) $serial]),
            $key,
        ));
    }

    /**
     * Looks again once the refund is stored, and withdraws it if its payment, or the order's
     * excess, is now asked for more than there is.
     *
     * The number a refund is stored under already keeps two requests for one payment from both
     * fitting. What it cannot see is a claim that changed without a new refund: another refund
     * of the payment executed in a larger amount meanwhile, or a surplus return created at the
     * same moment on *another* payment of the order. Each request that finds too much claimed
     * withdraws itself, so two that race may both be refused, and never both stay.
     *
     * @throws RefundRefusedException when the refund was withdrawn
     */
    private function withdrawIfOverClaimed(Refund $refund, RefundRequest $request): void
    {
        $overview = $this->receivedPayments->of($request->intent);
        $payment = $overview->find($refund->sourceHash);
        if ($payment === null) {
            return;
        }

        $claimed = BigDecimal::of($payment->refunded)->plus(BigDecimal::of($payment->pending));
        $notice = null;

        if ($claimed->isGreaterThan(BigDecimal::of($payment->delivered->value))) {
            $notice = new RefundNotice(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE, true, ['refundable' => '0']);
        } elseif ($refund->purpose === Refund::PURPOSE_SURPLUS_RETURN) {
            $returns = BigDecimal::zero();
            foreach ($overview->payments as $other) {
                foreach ($other->inQuotedAsset ? $other->refunds : [] as $existing) {
                    if ($existing->purpose === Refund::PURPOSE_SURPLUS_RETURN && $existing->claimedAmount() !== null) {
                        $returns = $returns->plus(BigDecimal::of($existing->claimedAmount()->value));
                    }
                }
            }

            if ($returns->isGreaterThan(BigDecimal::of($overview->surplus))) {
                $notice = new RefundNotice(RefundNotice::AMOUNT_EXCEEDS_SURPLUS, true, ['surplus' => $overview->surplusReturnable]);
            }
        }

        if ($notice === null) {
            return;
        }

        $this->refunds->update($refund->cancelled($this->now()), $refund->version);
        $this->logger->warning('LedgerDirect: refund withdrawn right after it was stored, another claim got there at the same moment', [
            'refund' => $refund->id,
            'source_hash' => $refund->sourceHash,
            'reason' => $notice->code,
        ]);

        throw new RefundRefusedException([$notice]);
    }

    /**
     * @return array{preview: RefundPreview, payment: ?ReceivedPayment, fiat: ?string}
     */
    private function assess(RefundRequest $request): array
    {
        $intent = $request->intent;
        $this->assertChain($intent);

        if (!in_array($request->purpose, Refund::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown refund purpose '{$request->purpose}'.");
        }

        // Every refund rests on a person: on who entered the destination, or on who confirmed
        // that the sender's address is the customer's own wallet. The record names them.
        if ($request->createdBy === null || trim($request->createdBy) === '') {
            throw new InvalidArgumentException('A refund request names who makes it (createdBy).');
        }

        $payingAccount = $intent->destinationAccount;
        $payment = $this->receivedPayments->of($intent)->find($request->sourceHash);
        if ($payment === null) {
            return self::refused($payingAccount, [new RefundNotice(RefundNotice::SOURCE_NOT_FOUND, true)]);
        }

        $notices = [];

        /*
         * The payment as the ledger has it now, not as it was stored: its source tag is in no
         * column, and a rule hangs on it.
         */
        $source = null;
        $ledgerDown = false;
        try {
            $result = $this->client->tx($payment->hash, $intent->network);
            $source = $result === null ? null : LedgerTransaction::fromTx($result);
        } catch (XrplRpcException $exception) {
            $notices[] = self::ledgerUnavailable($exception);
            $ledgerDown = true;
        }

        if ($source !== null && (
            !$source->succeeded()
            || $source->destination !== $payingAccount
            || $source->destinationTag !== $intent->destinationTag
            || $source->account !== $payment->sender
        )) {
            return self::refused($payingAccount, [new RefundNotice(RefundNotice::SOURCE_NOT_FOUND, true)]);
        }

        $limit = $payment->limitFor($request->purpose);
        [$amount, $fiat, $amountNotices] = $this->amount($request, $payment, $limit);
        array_push($notices, ...$amountNotices);

        [$account, $tag, $origin, $destinationNotices, $usable] = $this->destination($request, $payment);
        array_push($notices, ...$destinationNotices);

        $needsConfirmation = false;

        if ($usable) {
            $report = null;
            if (!$ledgerDown) {
                try {
                    // Without a usable amount the account's flags are still worth asking for.
                    $report = $this->destinationCheck->check($account, $intent->network, $amount ?? self::inAssetOf($payment->delivered, '0'));
                    array_push($notices, ...$report->notices);
                } catch (XrplRpcException $exception) {
                    $notices[] = self::ledgerUnavailable($exception);
                    $ledgerDown = true;
                }
            }

            // With the node down the request is refused already, and half an answer is no
            // ground for a verdict on the tag.
            if (!$ledgerDown && $tag === null) {
                $shared = $account === $payment->sender ? self::sharedSenderReason($report?->requiresTag, $source) : null;

                if ($shared !== null) {
                    $context = ['sender' => $payment->sender, 'reason' => $shared];
                    if ($source?->sourceTag !== null) {
                        $context['source_tag'] = (string) $source->sourceTag;
                    }
                    $notices[] = new RefundNotice(RefundNotice::SENDER_MAY_BE_SHARED, true, $context);
                } elseif ($report?->requiresTag === true) {
                    $notices[] = new RefundNotice(RefundNotice::TAG_REQUIRED, true, ['destination' => $account]);
                } elseif (!$request->ownWalletConfirmed) {
                    $notices[] = new RefundNotice(RefundNotice::CONFIRMATION_REQUIRED, true, ['destination' => $account]);
                    $needsConfirmation = true;
                }
            } elseif (!$ledgerDown && $report?->requiresTag === false) {
                // The ledger will not refuse a wrong or missing tag here; the wallet is the last place to look.
                $notices[] = new RefundNotice(RefundNotice::CHECK_TAG_IN_WALLET, false, ['destination' => $account]);
            }

            if ($source?->isCrossCurrency() === true && $source->sendMax !== null && $source->delivered !== null) {
                $notices[] = new RefundNotice(RefundNotice::PAID_ACROSS_ASSETS, false, [
                    'spent' => $this->assetName($source->sendMax),
                    'received' => $this->assetName($source->delivered),
                ]);
            }

            $toDestination = $this->refunds->findByDestination($intent->network, $payingAccount, $account);

            if ($amount !== null) {
                foreach ($toDestination as $existing) {
                    if ($existing->isAwaitingExecution() && $existing->destination->tag === $tag && $existing->amount->equals($amount)) {
                        $notices[] = new RefundNotice(RefundNotice::DUPLICATE_OPEN_REFUND, true, ['refund' => $existing->id]);
                        break;
                    }
                }
            }

            if (!$ledgerDown) {
                array_push($notices, ...$this->priorPayments($payingAccount, $account, $intent->network, $source, $toDestination));
            }
        }

        return [
            'preview' => new RefundPreview(
                notices: $notices,
                amount: $amount,
                limit: $limit,
                destination: $usable ? $account : null,
                destinationTag: $usable ? $tag : null,
                destinationOrigin: $origin,
                payingAccount: $payingAccount,
                needsOwnWalletConfirmation: $needsConfirmation,
                sourceTag: $source?->sourceTag,
            ),
            'payment' => $payment,
            'fiat' => $fiat,
        ];
    }

    /**
     * What the paying account already sent to the destination since the payment being refunded,
     * other than the executions of refunds on record: the refund may have been paid by hand
     * already. Also the one protection that holds if the refund table were ever lost.
     *
     * Advice, never a refusal. A history that cannot be read is said to be unread, not empty.
     *
     * @param list<Refund> $toDestination the refunds on record to this destination
     * @return list<RefundNotice>
     */
    private function priorPayments(string $payingAccount, string $destination, string $network, ?LedgerTransaction $source, array $toDestination): array
    {
        if ($source === null || $source->ledgerIndex === null) {
            return [new RefundNotice(RefundNotice::PRIOR_PAYMENTS_NOT_CHECKED, false)];
        }

        $onRecord = [];
        foreach ($toDestination as $refund) {
            if ($refund->executedHash !== null) {
                $onRecord[$refund->executedHash] = true;
            }
        }

        $notices = [];
        $marker = null;
        $pages = 0;

        try {
            do {
                // "From the payment's own ledger on": the client asks for the ledger after the one given.
                $page = $this->client->fetchAccountTransactions($payingAccount, $network, $source->ledgerIndex - 1, $marker);

                foreach ($page['transactions'] as $entry) {
                    $transaction = LedgerTransaction::fromAccountTx($entry);
                    if ($transaction->account !== $payingAccount || $transaction->destination !== $destination
                        || !$transaction->succeeded() || isset($onRecord[$transaction->hash])
                    ) {
                        continue;
                    }

                    if (count($notices) < self::PRIOR_PAYMENT_NOTICES) {
                        $notices[] = new RefundNotice(RefundNotice::PAID_BEFORE, false, [
                            'hash' => $transaction->hash,
                            'amount' => $transaction->delivered?->value ?? '',
                            'asset' => $transaction->delivered === null ? '' : $this->assetName($transaction->delivered),
                            'tag' => $transaction->destinationTag === null ? '' : (string) $transaction->destinationTag,
                        ]);
                    }
                }

                $marker = $page['marker'];
                $pages++;
            } while ($marker !== null && $pages < self::PRIOR_PAYMENT_PAGES);
        } catch (XrplRpcException) {
            $marker = 'unread';
        }

        if ($marker !== null) {
            $notices[] = new RefundNotice(RefundNotice::PRIOR_PAYMENTS_NOT_CHECKED, false);
        }

        return $notices;
    }

    /**
     * Why a sender is not paid back without a tag, or null when nothing speaks against it:
     * the account requires a tag, the payment carried a source tag, or the payment cannot be
     * read from the ledger and so nothing is known about it.
     */
    private static function sharedSenderReason(?bool $requiresTag, ?LedgerTransaction $source): ?string
    {
        return match (true) {
            $requiresTag === true => 'requires_tag',
            $source === null => 'source_unreadable',
            $source->sourceTag !== null => 'source_tag',
            default => null,
        };
    }

    /**
     * @return array{0: ?LedgerAmount, 1: ?string, 2: list<RefundNotice>} the tokens to ask for,
     *     the fiat amount it was derived from, and what is wrong with the request's amount
     */
    private function amount(RefundRequest $request, ReceivedPayment $payment, ?string $limit): array
    {
        $returnsAsItCame = $request->purpose === Refund::PURPOSE_WRONG_ASSET_RETURN;
        if ($payment->inQuotedAsset === $returnsAsItCame) {
            // An order refund of a payment in another asset, or a "wrong asset" that is the right one.
            return [null, null, [new RefundNotice(RefundNotice::PURPOSE_NOT_ALLOWED, true)]];
        }

        $ways = (int) ($request->amount !== null) + (int) ($request->fiatAmount !== null) + (int) $request->everything;
        if ($ways !== 1) {
            return [null, null, [new RefundNotice(RefundNotice::AMOUNT_INVALID, true)]];
        }

        $exceeds = $request->purpose === Refund::PURPOSE_SURPLUS_RETURN
            ? new RefundNotice(RefundNotice::AMOUNT_EXCEEDS_SURPLUS, true, ['surplus' => $limit ?? '0'])
            : new RefundNotice(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE, true, ['refundable' => $limit ?? '0']);

        if ($limit === null) {
            return [null, null, [$exceeds]];
        }

        $fiat = null;

        if ($request->everything) {
            $value = $limit;
        } elseif ($request->amount !== null) {
            $value = trim($request->amount);
        } else {
            $decimals = RefundAmount::decimalsFor($request->intent);
            // The quote's rate is for the quoted asset. For a payment in another asset it says
            // nothing: that one is returned in tokens, or as "everything".
            if (!$payment->inQuotedAsset || $decimals === null || $request->orderValue === null
                || !RefundAmount::orderValueMatchesQuote($request->intent, trim($request->orderValue))
            ) {
                return [null, null, [new RefundNotice(RefundNotice::FIAT_BASIS_MISMATCH, true)]];
            }

            $typed = trim((string) $request->fiatAmount);
            $requested = $request->intent->amountRequestedValue();
            $orderValue = trim($request->orderValue);

            try {
                $value = RefundAmount::fromFiat($typed, $requested, $orderValue, $decimals);
                $roundedDown = RefundAmount::fromFiat($typed, $requested, $orderValue, $decimals, roundUp: false);
                $fiat = PaymentIntent::plainDecimal(BigDecimal::of($typed));
            } catch (InvalidArgumentException) {
                return [null, null, [new RefundNotice(RefundNotice::AMOUNT_INVALID, true)]];
            }

            /*
             * Rounding up must not push the last refund over what is left: every share before
             * it was rounded up too. If the share fits rounded down, only the rounding is over
             * the limit, and the limit wins. A share that exceeds it even rounded down is a
             * real excess.
             */
            $left = BigDecimal::of($limit);
            if (BigDecimal::of($value)->isGreaterThan($left) && BigDecimal::of($roundedDown)->isLessThanOrEqualTo($left)) {
                $value = $limit;
            }
        }

        try {
            $amount = self::inAssetOf($payment->delivered, $value);
        } catch (InvalidArgumentException) {
            return [null, null, [new RefundNotice(RefundNotice::AMOUNT_INVALID, true)]];
        }

        $number = BigDecimal::of($amount->value);
        if (!$number->isPositive() || (!$amount->isNative() && !$request->everything && self::significantDigits($amount->value) > self::MAX_TOKEN_DIGITS)) {
            return [null, null, [new RefundNotice(RefundNotice::AMOUNT_INVALID, true)]];
        }

        if ($number->isGreaterThan(BigDecimal::of($limit))) {
            return [$amount, $fiat, [$exceeds]];
        }

        return [$amount, $fiat, []];
    }

    /**
     * Where the refund would go.
     *
     * @return array{0: string, 1: ?int, 2: string, 3: list<RefundNotice>, 4: bool} account, tag,
     *     origin, what is wrong with the destination as the request states it, and whether the
     *     account is one the ledger can be asked about
     */
    private function destination(RefundRequest $request, ReceivedPayment $payment): array
    {
        $stated = trim((string) $request->destination);
        $tagText = trim((string) $request->destinationTag);
        $repeatText = trim((string) $request->destinationTagRepeat);
        $notices = [];
        $tag = null;

        if ($stated === '') {
            $account = $payment->sender;
            $origin = RefundDestination::ORIGIN_SENDER;

            /*
             * The sender's address is never combined with a tag somebody typed: the account an
             * exchange pays out from is often not the one it takes deposits on. Address and tag
             * come together, from the customer, as a stated destination.
             */
            if ($tagText !== '' || $repeatText !== '') {
                $notices[] = new RefundNotice(RefundNotice::TAG_INVALID, true, ['reason' => 'tag_without_destination']);
            }
        } else {
            $account = $stated;
            $origin = RefundDestination::ORIGIN_STATED;

            if (!ChainCatalog::chain($this->chain)->isValidAccount($account)) {
                return [$account, null, $origin, [new RefundNotice(RefundNotice::DESTINATION_INVALID, true)], false];
            }

            if ($tagText !== '' || $repeatText !== '') {
                $first = self::parseTag($tagText);
                $second = self::parseTag($repeatText);

                if ($first === null) {
                    $notices[] = new RefundNotice(RefundNotice::TAG_INVALID, true, ['reason' => 'not_a_tag']);
                } elseif ($first !== $second) {
                    $notices[] = new RefundNotice(RefundNotice::TAG_INVALID, true, ['reason' => 'repetition_differs']);
                } else {
                    $tag = $first;
                }
            }
        }

        if ($account === $request->intent->destinationAccount) {
            return [$account, $tag, $origin, [new RefundNotice(RefundNotice::DESTINATION_IS_PAYING_ACCOUNT, true)], false];
        }

        if ($account === $payment->delivered->issuer) {
            return [$account, $tag, $origin, [new RefundNotice(RefundNotice::DESTINATION_IS_ISSUER, true)], false];
        }

        return [$account, $tag, $origin, $notices, true];
    }

    /** A destination tag as typed: digits only, within 32 bits. Null for anything else. */
    private static function parseTag(string $text): ?int
    {
        if ($text === '' || !ctype_digit($text) || strlen(ltrim($text, '0')) > 10) {
            return null;
        }

        $tag = (int) $text;

        return $tag > RefundDestination::MAX_TAG ? null : $tag;
    }

    /** An amount in the asset of another one. */
    private static function inAssetOf(LedgerAmount $asset, string $value): LedgerAmount
    {
        return $asset->isNative()
            ? LedgerAmount::native($value)
            : LedgerAmount::issued((string) $asset->currency, (string) $asset->issuer, $value);
    }

    /** How many digits the value needs: leading and trailing zeros do not count. */
    private static function significantDigits(string $plain): int
    {
        return strlen(trim(str_replace('.', '', $plain), '0'));
    }

    private function assetName(LedgerAmount $amount): string
    {
        return $amount->currency ?? ChainCatalog::chain($this->chain)->nativeAsset;
    }

    private function reference(string $network, string $sourceHash, int $serial): string
    {
        if ($this->referenceKey === null || $this->referenceKey === '') {
            return strtoupper(bin2hex(random_bytes(32)));
        }

        return self::deriveReference($this->referenceKey, $this->chain, $network, $sourceHash, $serial);
    }

    private function assertChain(PaymentIntent $intent): void
    {
        if ($intent->chain !== $this->chain) {
            throw new InvalidArgumentException("This refund service is for {$this->chain}; the intent is on {$intent->chain}.");
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @param array<string, string> $context
     */
    private static function refusal(string $code, array $context = []): RefundRefusedException
    {
        return new RefundRefusedException([new RefundNotice($code, true, $context)]);
    }

    private static function ledgerUnavailable(XrplRpcException $exception): RefundNotice
    {
        return new RefundNotice(RefundNotice::LEDGER_UNAVAILABLE, true, ['error' => $exception->getMessage()]);
    }

    /**
     * @param list<RefundNotice> $notices
     * @return array{preview: RefundPreview, payment: null, fiat: null}
     */
    private static function refused(string $payingAccount, array $notices): array
    {
        return [
            'preview' => new RefundPreview(
                notices: $notices,
                amount: null,
                limit: null,
                destination: null,
                destinationTag: null,
                destinationOrigin: null,
                payingAccount: $payingAccount,
                needsOwnWalletConfirmation: false,
                sourceTag: null,
            ),
            'payment' => null,
            'fiat' => null,
        ];
    }
}
