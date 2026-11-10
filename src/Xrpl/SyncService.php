<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Sync\LedgerSyncInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Syncs an XRPL account's incoming transactions into storage, deduplicated
 * against what's already there, and matches a stored transaction against a
 * destination account/tag (the "Sync + Dedup + Match" service CLAUDE.md
 * names).
 */
final class SyncService implements LedgerSyncInterface
{
    private const MAX_PAGES = 1000;

    /**
     * Where a reset's cursor is remembered: chain, network and the account as a digest, like
     * SyncThrottle's key, but only from characters PSR-16 guarantees (at most 56 of 64).
     */
    private const EPOCH_KEY_PREFIX = 'ledger_direct.cursor.v1';

    /** Refreshed by every sync of the account, so it only lapses for an account nobody syncs. */
    private const EPOCH_TTL_SECONDS = 2592000;

    /**
     * @param CacheInterface|null $cache remembers the cursor of a new testnet epoch (see
     *     syncTransactions()); without one every sync after a reset starts with a failing request
     * @param string $chain part of the cache key: the same account can exist on the XRP Ledger and
     *     on Xahau
     */
    public function __construct(
        private readonly XrplClient $xrplClient,
        private readonly XrplTransactionRepositoryInterface $transactionRepository,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $cache = null,
        private readonly string $chain = 'XRPL',
    ) {
    }

    /**
     * Fetches new incoming transactions for $address since the last sync
     * and stores them, paginating until exhausted (capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies).
     */
    public function sync(string $account, string $network): void
    {
        $this->syncTransactions($account, $network);
    }

    /**
     * After a testnet reset the stored cursor, the highest stored ledger index, stays above the
     * live ledger for the whole new epoch: the rows from before the reset are kept, and new rows
     * sit below them. The first sync notices it (lgrIdxsInvalid) and resyncs without a cursor.
     * With a cache it then remembers that stale cursor together with the highest ledger the
     * resync saw, and while the repository still reports that stale cursor, later syncs continue
     * from the remembered ledger instead of failing again. Once the repository reports anything
     * else, the note no longer applies and is ignored. Without a cache, or with the note evicted,
     * the behaviour is the one before: a failing request and a full resync on every sync.
     */
    public function syncTransactions(string $address, string $network): void
    {
        $lastSyncedLedgerIndex = $this->transactionRepository->getLastSyncedLedgerIndex($address, $network);
        $storedCursor = $lastSyncedLedgerIndex !== null ? (int) $lastSyncedLedgerIndex : null;

        $epoch = $storedCursor !== null ? $this->readEpoch($address, $network) : null;
        if ($epoch !== null && $epoch['stale'] !== $storedCursor) {
            $epoch = null;
        }

        $afterLedgerIndex = $epoch !== null ? $epoch['cursor'] : $storedCursor;

        try {
            $highest = $this->syncFrom($address, $network, $afterLedgerIndex);
            if ($epoch !== null) {
                $this->writeEpoch($address, $network, $epoch['stale'], self::higher($epoch['cursor'], $highest));
            }
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

            $highest = $this->syncFrom($address, $network, null);

            if ($storedCursor !== null) {
                $this->writeEpoch($address, $network, $storedCursor, $highest);
            }
        }
    }

    /**
     * @return array{stale: int, cursor: ?int}|null
     */
    private function readEpoch(string $address, string $network): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $value = $this->cache->get($this->epochKey($address, $network));
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: reading the reset cursor failed, syncing from the stored one', [
                'address' => $address,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        // Anything else is not a note this code wrote; read it as no note at all.
        if (!is_array($value) || !is_int($value['stale'] ?? null) || !array_key_exists('cursor', $value)
            || !($value['cursor'] === null || is_int($value['cursor']))) {
            return null;
        }

        return ['stale' => $value['stale'], 'cursor' => $value['cursor']];
    }

    private function writeEpoch(string $address, string $network, int $stale, ?int $cursor): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                $this->epochKey($address, $network),
                ['stale' => $stale, 'cursor' => $cursor],
                self::EPOCH_TTL_SECONDS,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: remembering the reset cursor failed', [
                'address' => $address,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function epochKey(string $address, string $network): string
    {
        return implode('.', [
            self::EPOCH_KEY_PREFIX,
            strtolower($this->chain),
            $network,
            substr(hash('sha256', $address), 0, 16),
        ]);
    }

    private static function higher(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /**
     * One pass of the paginated fetch-and-store loop, capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies.
     *
     * @return int|null the highest ledger index of any transaction fetched, null when none came
     */
    private function syncFrom(string $address, string $network, ?int $afterLedgerIndex): ?int
    {
        $marker = null;
        $pages = 0;
        $highest = null;

        do {
            $page = $this->xrplClient->fetchAccountTransactions($address, $network, $afterLedgerIndex, $marker);

            foreach ($page['transactions'] as $raw) {
                $index = $raw['tx']['ledger_index'] ?? null;
                if (is_numeric($index)) {
                    $highest = self::higher($highest, (int) $index);
                }
            }

            if ($page['transactions'] !== []) {
                $this->storeIncomingNewTransactions($page['transactions'], $address, $network);
            }

            $marker = $page['marker'];
            $pages++;
        } while ($marker !== null && $pages < self::MAX_PAGES);

        return $highest;
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
        foreach ($this->deliveredCandidates($intent) as [$candidate, $delivered]) {
            // The old behaviour, kept here alone: the other asset class is noise to this method.
            if (is_array($delivered) !== is_array($intent->amountRequested)) {
                $this->logger->warning('LedgerDirect: payment in a different asset class on this tag skipped', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'quoted_asset' => $intent->baseAsset,
                ]);
                continue;
            }

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
     * one (the other asset class — a token on a native quote or XRP on a
     * token quote —, the same currency code from another issuer, or another
     * token from the right issuer), the newest such transaction alone is the
     * fulfillment, so SettlementPolicy can declare it wrong-asset with the
     * delivered amount visible. As soon as one payment in the quoted asset
     * exists, only those count and the foreign ones are logged.
     *
     * A non-payment and an unreadable delivered amount are skipped and
     * logged, never a reason to abort — unchanged from findTransactionFor().
     * The other asset class is *not* skipped here, unlike there: a customer
     * who sent RLUSD for an XRP order has paid and must be told why nothing
     * was credited.
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
     * Every transaction on the intent's tag that delivered something,
     * newest first, paired with its decoded delivered amount — in either
     * asset class. The skipping rules — unreadable amount, non-payment —
     * are shared by findTransactionFor() and findFulfillmentFor(); only
     * findTransactionFor() still drops the other asset class on its own.
     *
     * @return iterable<array{0: XrplTransaction, 1: float|array{currency: string, value: string, issuer: string}}>
     */
    private function deliveredCandidates(PaymentIntent $intent): iterable
    {
        foreach ($this->findTransactions($intent->destinationAccount, $intent->destinationTag) as $candidate) {
            if ($candidate->network !== $intent->network) {
                /*
                 * The same key pair is a valid account on testnet and mainnet,
                 * and the tag counter is per account, not per network. A
                 * merchant who switches the network keeps account and tag on
                 * a refreshed quote, so a testnet payment would otherwise
                 * settle a mainnet order. Test money never pays a real one.
                 */
                $this->logger->warning('LedgerDirect: skipping a transaction from another network on this tag', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'transaction_network' => $candidate->network,
                    'intent_network' => $intent->network,
                ]);

                continue;
            }

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

            yield [$candidate, $delivered];
        }
    }

    /**
     * Whether a delivered amount is in exactly the quoted asset: the same
     * asset class first of all, then — for an issued currency — the quoted
     * currency code and issuer. The same rule SettlementPolicy::isWrongAsset()
     * states as a verdict, used here as a filter.
     *
     * @param float|array{currency: string, value: string, issuer: string} $delivered
     */
    private static function isQuotedAsset(PaymentIntent $intent, float|array $delivered): bool
    {
        if (is_array($intent->amountRequested) !== is_array($delivered)) {
            return false;
        }

        if (!is_array($intent->amountRequested)) {
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

        /*
         * Checked here, before any adapter stores it: the column is sixteen
         * characters wide, and not every database refuses a longer value —
         * MySQL under INSERT IGNORE, or with the strict modes WordPress
         * switches off, truncates silently. A CTID that is not a CTID is a
         * malformed entry like a missing hash, skipped and logged.
         */
        if (!XrplTransaction::isValidCtid((string) $tx['ctid'])) {
            throw new UnexpectedValueException(
                "Synced transaction {$tx['hash']} carries a malformed ctid '{$tx['ctid']}' (expected C + 15 hex digits)."
            );
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
