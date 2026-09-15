<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Syncs an XRPL account's incoming transactions into storage, deduplicated
 * against what's already there, and matches a stored transaction against a
 * destination account/tag (the "Sync + Dedup + Match" service CLAUDE.md
 * names).
 */
final class SyncService
{
    private const MAX_PAGES = 1000;

    public function __construct(
        private readonly XrplClient $xrplClient,
        private readonly XrplTransactionRepositoryInterface $transactionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches new incoming transactions for $address since the last sync
     * and stores them, paginating until exhausted (capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies).
     */
    public function syncTransactions(string $address, string $network): void
    {
        $lastSyncedLedgerIndex = $this->transactionRepository->getLastSyncedLedgerIndex($address, $network);
        $afterLedgerIndex = $lastSyncedLedgerIndex !== null ? (int) $lastSyncedLedgerIndex : null;

        try {
            $this->syncFrom($address, $network, $afterLedgerIndex);
        } catch (XrplRpcException $exception) {
            /*
             * The testnet is reset periodically, which rewinds its ledger
             * index. Rows from the previous epoch then sit far above the
             * live ledger, the cursor built from them is unservable, and
             * rippled answers lgrIdxsInvalid — for every order, forever,
             * until somebody deletes rows by hand. Recover by doing the one
             * thing that is guaranteed to be servable: ask again with no
             * cursor. findExistingHashes() already keeps that from
             * duplicating anything.
             *
             * Only retried when a cursor was actually sent; without one,
             * lgrIdxsInvalid means something else entirely and retrying the
             * identical call would just fail the same way.
             */
            if ($afterLedgerIndex === null || $exception->error !== XrplClient::ERROR_LEDGER_INDEXES_INVALID) {
                throw $exception;
            }

            $this->logger->warning(
                'LedgerDirect: stored ledger cursor is ahead of the network, resyncing without one',
                [
                    'address' => $address,
                    'network' => $network,
                    'cursor' => $afterLedgerIndex,
                ],
            );

            $this->syncFrom($address, $network, null);
        }
    }

    /**
     * One pass of the paginated fetch-and-store loop, capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies.
     */
    private function syncFrom(string $address, string $network, ?int $afterLedgerIndex): void
    {
        $marker = null;
        $pages = 0;

        do {
            $page = $this->xrplClient->fetchAccountTransactions($address, $network, $afterLedgerIndex, $marker);

            if ($page['transactions'] !== []) {
                $this->storeIncomingNewTransactions($page['transactions'], $address, $network);
            }

            $marker = $page['marker'];
            $pages++;
        } while ($marker !== null && $pages < self::MAX_PAGES);
    }

    /**
     * Every synced transaction on this destination account/tag, newest
     * first. A thin delegate — exists so a platform integration has one
     * service for the whole "did this order get paid" flow instead of
     * needing XrplTransactionRepositoryInterface injected separately just
     * for this. Use it to *show* what arrived on a tag; use
     * findFulfillmentFor() to decide which of them pay an order.
     *
     * @return XrplTransaction[]
     */
    public function findTransactions(string $destinationAccount, int $destinationTag): array
    {
        return $this->transactionRepository->findTransactions($destinationAccount, $destinationTag);
    }

    /**
     * The single newest transaction on the intent's tag in the quoted
     * asset *class* — a native float for a native quote, an
     * issued-currency object for an issued-currency quote — with a
     * wrong-issuer candidate kept so SettlementPolicy can reject it.
     *
     * Looks at one transaction only. Two partial payments therefore never
     * add up here, and a top-up of the shortfall can never settle: the
     * newest payment is judged on its own. That is exactly what
     * findFulfillmentFor() fixes. This method keeps its 0.3–0.5 behaviour
     * unchanged so an adapter that has not moved yet is not surprised.
     *
     * @deprecated since 0.6.0, use {@see findFulfillmentFor()}. Removed in 1.0.
     */
    public function findTransactionFor(PaymentIntent $intent): ?XrplTransaction
    {
        foreach ($this->deliveredCandidates($intent) as [$candidate]) {
            return $candidate;
        }

        return null;
    }

    /**
     * The "Match" part: which transactions on the intent's tag pay for it,
     * and what they delivered in total.
     *
     * Every candidate in the **quoted asset** counts — for a native asset
     * every native amount on the tag, for an issued currency only those
     * with the quoted currency *and* issuer — and `amountPaid` is their
     * sum. Two partial payments add up; a customer who sends the shortfall
     * settles. This is what makes the payment page's "X received, Y still
     * outstanding" an instruction that can actually be followed.
     *
     * If nothing arrived in the quoted asset but something did in another
     * one (same currency code from another issuer, or another token from
     * the right issuer), the newest such transaction alone is the
     * fulfillment, so SettlementPolicy can declare it wrong-asset with the
     * delivered amount visible. As soon as one payment in the quoted asset
     * exists, only those count and the foreign ones are logged.
     *
     * A class mismatch, a non-payment and an unreadable delivered amount
     * are skipped and logged, never a reason to abort — unchanged from
     * findTransactionFor().
     *
     * The intent records the newest contributing hash and ctid; the whole
     * list is on the Fulfillment and in the transaction table.
     */
    public function findFulfillmentFor(PaymentIntent $intent): ?Fulfillment
    {
        $quoted = [];
        $foreign = [];

        foreach ($this->deliveredCandidates($intent) as [$candidate, $delivered]) {
            if (self::isQuotedAsset($intent, $delivered)) {
                $quoted[] = [$candidate, $delivered];
            } else {
                $foreign[] = [$candidate, $delivered];
            }
        }

        if ($quoted !== []) {
            $transactions = array_map(static fn (array $pair): XrplTransaction => $pair[0], $quoted);
            $amounts = array_map(static fn (array $pair): float|array => $pair[1], $quoted);

            if (count($transactions) > 1) {
                $this->logger->info('LedgerDirect: several payments in the quoted asset on this tag add up', [
                    'destination_tag' => $intent->destinationTag,
                    'count' => count($transactions),
                    'hashes' => array_map(static fn (XrplTransaction $t): string => $t->hash, $transactions),
                ]);
            }

            foreach ($foreign as [$candidate]) {
                $this->logger->warning('LedgerDirect: payment in another asset on this tag ignored, the order has payments in the quoted asset', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'quoted_asset' => $intent->baseAsset,
                ]);
            }

            return new Fulfillment($transactions, self::sum($intent, $amounts));
        }

        if ($foreign !== []) {
            [$candidate, $delivered] = $foreign[0];

            return new Fulfillment([$candidate], $delivered);
        }

        return null;
    }

    /**
     * Every transaction on the intent's tag that delivered something in the
     * quoted asset *class*, newest first, paired with its decoded delivered
     * amount. The skipping rules — unreadable amount, non-payment, other
     * class — are shared by findTransactionFor() and findFulfillmentFor()
     * and behave identically in both.
     *
     * @return iterable<array{0: XrplTransaction, 1: float|array{currency: string, value: string, issuer: string}}>
     */
    private function deliveredCandidates(PaymentIntent $intent): iterable
    {
        $quotedIssuedCurrency = is_array($intent->amountRequested);

        foreach ($this->findTransactions($intent->destinationAccount, $intent->destinationTag) as $candidate) {
            try {
                $delivered = $candidate->getDeliveredAmount();
            } catch (UnexpectedValueException $exception) {
                /*
                 * The ledger's own 'unavailable' marker, or a delivered
                 * amount of an unexpected type. Skip it rather than abort:
                 * a later candidate may well be the real payment.
                 */
                $this->logger->warning('LedgerDirect: skipping a transaction with an unreadable delivered amount', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($delivered === null) {
                // Carries a Destination but delivered nothing measurable — an
                // EscrowCreate or CheckCreate lands in the same table. Routine.
                $this->logger->debug('LedgerDirect: skipping a non-payment transaction on this tag', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                ]);

                continue;
            }

            if (is_array($delivered) !== $quotedIssuedCurrency) {
                $this->logger->warning('LedgerDirect: payment in a different asset class on this tag skipped', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'quoted_asset' => $intent->baseAsset,
                ]);

                continue;
            }

            yield [$candidate, $delivered];
        }
    }

    /**
     * Whether a delivered amount is in exactly the quoted asset. A native
     * amount always is (there is no issuer to mismatch); an issued currency
     * only with the quoted currency code and issuer — the same rule
     * SettlementPolicy::isWrongAsset() states as a verdict, used here as a
     * filter.
     *
     * @param float|array{currency: string, value: string, issuer: string} $delivered
     */
    private static function isQuotedAsset(PaymentIntent $intent, float|array $delivered): bool
    {
        if (!is_array($intent->amountRequested) || !is_array($delivered)) {
            return true;
        }

        return $delivered['currency'] === $intent->amountRequested['currency']
            && $delivered['issuer'] === $intent->amountRequested['issuer'];
    }

    /**
     * Exact sum via BigDecimal, then back to the record shape: a float for
     * a native asset, a plain decimal string in the quoted envelope for an
     * issued currency. 0.1 + 0.2 as floats is 0.30000000000000004, and
     * amountPaidValue() and shortfall() would carry that noise through.
     *
     * @param list<float|array{currency: string, value: string, issuer: string}> $amounts
     * @return float|array{currency: string, value: string, issuer: string}
     */
    private static function sum(PaymentIntent $intent, array $amounts): float|array
    {
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus(BigDecimal::of(is_array($amount) ? (string) $amount['value'] : (string) $amount));
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

    /**
     * @param array<int, array<string, mixed>> $rawTransactions
     */
    private function storeIncomingNewTransactions(array $rawTransactions, string $ownAddress, string $network): void
    {
        $incoming = array_values(array_filter(
            $rawTransactions,
            static fn (array $raw): bool => ($raw['tx']['Destination'] ?? null) === $ownAddress,
        ));

        if ($incoming === []) {
            return;
        }

        $hashes = array_map(static fn (array $raw): string => (string) ($raw['tx']['hash'] ?? ''), $incoming);
        $existingHashes = $this->transactionRepository->findExistingHashes($hashes);

        $new = array_values(array_filter(
            $incoming,
            static fn (array $raw): bool => !in_array($raw['tx']['hash'] ?? '', $existingHashes, true),
        ));

        if ($new === []) {
            return;
        }

        $hydrated = [];
        foreach ($new as $raw) {
            try {
                $hydrated[] = self::hydrate($raw, $network);
            } catch (Throwable $exception) {
                $this->logger->warning('LedgerDirect: skipping malformed synced transaction', [
                    'hash' => $raw['tx']['hash'] ?? null,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($hydrated !== []) {
            $this->transactionRepository->saveTransactions($hydrated);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function hydrate(array $raw, string $network): XrplTransaction
    {
        $tx = $raw['tx'] ?? throw new UnexpectedValueException("Missing 'tx' in synced transaction.");

        foreach (['ledger_index', 'hash', 'ctid', 'Account', 'Destination', 'date'] as $requiredField) {
            if (!isset($tx[$requiredField])) {
                throw new UnexpectedValueException("Synced transaction is missing required field '{$requiredField}'.");
            }
        }

        return new XrplTransaction(
            network: $network,
            ledgerIndex: (string) $tx['ledger_index'],
            hash: (string) $tx['hash'],
            ctid: (string) $tx['ctid'],
            account: (string) $tx['Account'],
            destination: (string) $tx['Destination'],
            destinationTag: isset($tx['DestinationTag']) ? (int) $tx['DestinationTag'] : null,
            date: (int) $tx['date'],
            meta: $raw['meta'] ?? [],
            tx: $tx,
        );
    }
}
