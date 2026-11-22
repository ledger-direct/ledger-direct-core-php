<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\ReconcileResult;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundChange;
use Hardcastle\LedgerDirect\Core\Sync\LedgerSyncInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Finds out what became of the refunds a paying account owes: reads the account's own
 * transactions from the ledger and holds every outgoing one against its refunds
 * ({@see RefundMatcher}). A refund is executed when its payment is found here, and nowhere else.
 *
 * A pass of its own, apart from the payment sync, which stores incoming transactions only and
 * whose cursor follows them. Read here is every transaction the account sent, from the ledger
 * the oldest refund still looked after was created in — a bound taken from the ledger, not from
 * a clock — whatever its type and whether or not it succeeded: a refused payment is in the
 * sender's list too, and the merchant should learn why nothing arrived.
 *
 * Looked after are the refunds awaiting execution and, for GRACE_SECONDS, those executed or
 * cancelled: a second payment, or one after a cancellation, still has to be noticed.
 *
 * Reading is strict. An answer that cannot be relied on, a node that does not answer, a page
 * budget that runs out: the result says "incomplete" and nothing is concluded from the gap.
 * `lgrIdxsInvalid` changes no state here; the payment sync's recovery after a testnet reset is
 * not borrowed.
 *
 * Running the same pass twice changes nothing the second time: a transaction that executed a
 * refund is skipped, and a refund keeps one finding per transaction. The cursor kept in the
 * cache therefore only saves reading; without a cache every pass starts at the oldest refund.
 */
final class RefundReconciler implements LedgerSyncInterface
{
    /** How long a refund is looked after once it is executed or cancelled: seven days. */
    public const GRACE_SECONDS = 604800;

    /** How often an account is read that only has refunds in their grace period: once an hour. */
    public const GRACE_INTERVAL_SECONDS = 3600;

    /** Pages of 200 transactions one pass reads at most. */
    public const DEFAULT_MAX_PAGES = 25;

    /**
     * A refund created this far beyond the node's newest ledger is from another epoch of a test
     * network that was reset, not from a node that lags a few ledgers behind.
     */
    private const EPOCH_GAP = 1000;

    /** How often a transaction is matched again when another process changed a refund meanwhile. */
    private const MAX_ATTEMPTS = 3;

    /**
     * Pages a pass reads at most to get past one ledger, whatever its budget: a budget that ends
     * in the middle of a ledger with more transactions than it has pages for would otherwise
     * read the same pages on every pass and never arrive.
     */
    private const HARD_MAX_PAGES = 200;

    /**
     * How many ledgers the `account_tx` answer may end before the newest validated ledger and
     * still count as read to the end: a little more than a minute. The two answers come from a
     * pool of nodes that are rarely on the very same ledger; a node that is further behind has
     * not seen what a pass is asked about.
     */
    private const MAX_LAG_LEDGERS = 20;

    private const CACHE_KEY_PREFIX = 'ledger_direct.refund.v1';

    /** Refreshed by every pass; lapses for an account nobody reads. */
    private const CACHE_TTL_SECONDS = 2592000;

    private readonly ClockInterface $clock;

    private readonly RefundMatcher $matcher;

    /**
     * @param CacheInterface|null $cache remembers per account how far the ledger has been read
     *     and when; saves reading, decides nothing
     * @param string $chain 'XRPL' or 'XAHAU', as the client and the repository it is given
     */
    public function __construct(
        private readonly XrplClient $client,
        private readonly RefundRepositoryInterface $refunds,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
        private readonly ?CacheInterface $cache = null,
        private readonly string $chain = ChainCatalog::XRPL,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->matcher = new RefundMatcher();
    }

    /**
     * For SyncThrottle::syncIfDue(). A caller that wants to know what the pass found — to book
     * an execution, to tell the merchant about a finding — calls reconcile() itself.
     */
    public function sync(string $account, string $network): void
    {
        $this->reconcile($account, $network);
    }

    /**
     * Every paying account with a refund that is looked after: those with an open refund on
     * every call, those with refunds in their grace period only once per GRACE_INTERVAL_SECONDS.
     * What a scheduled task calls.
     *
     * @return list<ReconcileResult> one per account that was read
     */
    public function reconcileWatched(string $network, int $maxPages = self::DEFAULT_MAX_PAGES): array
    {
        $now = $this->now();
        $results = [];

        foreach ($this->refunds->watchedAccounts($network, $now - self::GRACE_SECONDS) as $account) {
            $open = false;
            foreach ($this->refunds->findWatched($network, $account, $now - self::GRACE_SECONDS) as $refund) {
                $open = $open || $refund->isAwaitingExecution();
            }

            $lastRead = $this->note($account, $network)['at'] ?? null;
            if (!$open && $lastRead !== null && $now - $lastRead < self::GRACE_INTERVAL_SECONDS) {
                continue;
            }

            $results[] = $this->reconcile($account, $network, $maxPages);
        }

        return $results;
    }

    /**
     * One pass over one paying account, now.
     *
     * @param int $maxPages pages of 200 transactions to read at most; a small number for a call
     *     a person waits for, the default for a scheduled task
     */
    public function reconcile(string $account, string $network, int $maxPages = self::DEFAULT_MAX_PAGES): ReconcileResult
    {
        $now = $this->now();
        $closedSince = $now - self::GRACE_SECONDS;
        $watched = $this->refunds->findWatched($network, $account, $closedSince);

        if ($watched === []) {
            return new ReconcileResult($account, $network, true);
        }

        $executed = [];
        $findings = [];
        $unreachable = [];

        try {
            $validated = $this->client->validatedLedgerIndex($network);

            $oldest = null;
            foreach ($watched as $refund) {
                // Only a test network is ever reset. On mainnet a node that far behind is a
                // node that is behind, and nothing follows from what it has not seen.
                if ($network !== 'mainnet' && $refund->createdLedger > $validated + self::EPOCH_GAP) {
                    $unreachable[] = $refund;
                } elseif ($refund->createdLedger > $validated) {
                    // The node is behind the one that saw the refund created.
                    return new ReconcileResult(
                        $account,
                        $network,
                        false,
                        "The node's newest ledger {$validated} is older than the refund's ({$refund->createdLedger}).",
                        unreachable: $unreachable,
                    );
                } else {
                    $oldest = $oldest === null ? $refund->createdLedger : min($oldest, $refund->createdLedger);
                }
            }

            if ($oldest === null) {
                return new ReconcileResult($account, $network, true, unreachable: $unreachable);
            }

            // fetchAccountTransactions() asks for the ledger after the one it is given, and the
            // ledger a refund was created in is read as well: "from that ledger on".
            $after = $oldest - 1;
            $cursor = $this->note($account, $network)['cursor'] ?? null;
            // A cursor beyond the node's newest ledger is from another epoch, or from a node that was ahead.
            if ($cursor !== null && $cursor <= $validated) {
                $after = max($after, $cursor);
            }

            $gap = null;
            $highest = null;
            // How far the answers themselves say they go; unknown until a page says so.
            $covered = null;
            $read = false;
            $marker = null;

            if ($after < $validated) {
                $pages = 0;
                $read = true;

                do {
                    $page = $this->client->fetchAccountTransactions($account, $network, $after, $marker);
                    if ($page['ledger_index_max'] !== null) {
                        $covered = $covered === null ? $page['ledger_index_max'] : min($covered, $page['ledger_index_max']);
                    }

                    foreach ($page['transactions'] as $entry) {
                        $transaction = LedgerTransaction::fromAccountTx($entry);
                        if ($transaction->ledgerIndex !== null) {
                            $highest = $highest === null ? $transaction->ledgerIndex : max($highest, $transaction->ledgerIndex);
                        }

                        $changes = $this->apply($transaction, $account, $network, $now, $closedSince);
                        if ($changes === null) {
                            // The transaction is neither matched nor ruled out. The cursor stays
                            // where it was, so the next pass meets this transaction again.
                            return new ReconcileResult(
                                $account,
                                $network,
                                false,
                                "Transaction {$transaction->hash} could not be held against its refunds: they kept changing.",
                                $this->asStored($executed),
                                $findings,
                                $unreachable,
                            );
                        }

                        foreach ($changes as $change) {
                            if ($change->isExecution()) {
                                $executed[] = $change->after;
                            } else {
                                $findings[] = $change;
                            }
                        }
                    }

                    $marker = $page['marker'];
                    $pages++;
                    // Within the budget, or beyond it while no ledger has been read to its end yet.
                    $noLedgerDone = $highest === null || $highest - 1 <= $after;
                } while ($marker !== null && ($pages < $maxPages || ($noLedgerDone && $pages < self::HARD_MAX_PAGES)));

                /*
                 * "Read to the end" is a statement about ledgers, and only the answer itself can
                 * make it: it has to say which ledgers it covers, and they have to reach the
                 * newest validated one (give or take the few ledgers two nodes of a pool differ
                 * by). An answer that ends earlier comes from a node that has not seen what
                 * happened since, and "nothing found" there is not "nothing happened".
                 */
                $gap = match (true) {
                    $marker !== null => "Stopped after {$pages} pages; the rest is read by the next pass.",
                    $covered === null => 'The node did not say which ledgers its answer covers.',
                    $covered < $validated - self::MAX_LAG_LEDGERS => "The answer covers ledgers up to {$covered}; the newest validated ledger is {$validated}.",
                    default => null,
                };
            }

            $this->remember($account, $network, self::reached($after, $validated, $read, $marker !== null, $covered, $highest), $now);

            return new ReconcileResult(
                $account,
                $network,
                $gap === null,
                $gap,
                $this->asStored($executed),
                $findings,
                $unreachable,
            );
        } catch (XrplRpcException $exception) {
            $this->logger->warning('LedgerDirect: refund reconcile could not read the ledger to the end', [
                'chain' => $this->chain,
                'network' => $network,
                'account' => $account,
                'exception' => $exception->getMessage(),
            ]);

            // What was found before the failure is stored and reported; nothing follows from the rest.
            return new ReconcileResult($account, $network, false, $exception->getMessage(), $this->asStored($executed), $findings, $unreachable);
        }
    }

    /**
     * The refunds as they are stored at the end of the pass: a later transaction of the same
     * pass may have added a finding to a refund an earlier one executed.
     *
     * @param list<Refund> $refunds
     * @return list<Refund>
     */
    private function asStored(array $refunds): array
    {
        $stored = [];
        foreach ($refunds as $refund) {
            $stored[] = $this->refunds->find($refund->id) ?? $refund;
        }

        return $stored;
    }

    /**
     * The ledger up to which the account's transactions are known to have been read: where the
     * next pass may start after.
     *
     * Never further than the answers themselves say they went. The newest validated ledger
     * comes from `server_state`, the transactions from `account_tx`, and on a pool of nodes the
     * second answer may come from a node a few ledgers behind the first: a cursor set to the
     * first node's ledger would skip what the second had not seen yet, for as long as the
     * cursor lives. An answer that does not state its range advances the cursor only to the
     * ledger before the newest transaction it returned.
     *
     * Cut off at the page budget, the last ledger may be only partly read; the next pass
     * starts with it again.
     */
    private static function reached(int $after, int $validated, bool $read, bool $cutOff, ?int $covered, ?int $highest): int
    {
        if (!$read) {
            return $after;
        }

        $beforeNewest = $highest === null ? $after : max($after, $highest - 1);
        if ($cutOff) {
            return $beforeNewest;
        }

        return max($after, min($validated, $covered ?? $beforeNewest));
    }

    /**
     * Holds one transaction against the refunds it may concern and stores what follows.
     *
     * @return list<RefundChange>|null what was stored; null when the refunds kept changing
     *     under it and the transaction is neither matched nor ruled out
     */
    private function apply(LedgerTransaction $transaction, string $account, string $network, int $now, int $closedSince): ?array
    {
        // Only what the paying account itself sent to somebody.
        if ($transaction->account !== $account || $transaction->destination === null || !$transaction->validated) {
            return [];
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $toDestination = $this->refunds->findByDestination($network, $account, $transaction->destination);
            $referenced = $transaction->invoiceId === null ? null : $this->refunds->findByReference($network, $transaction->invoiceId);
            // A reference from another paying account's refund is not this account's to answer.
            if ($referenced !== null && $referenced->payingAccount !== $account) {
                $referenced = null;
            }

            $changes = $this->matcher->match($transaction, $toDestination, $referenced, $now, $closedSince);
            $stored = [];
            $refused = false;

            foreach ($changes as $change) {
                if ($this->refunds->update($change->after, $change->before->version)) {
                    $stored[] = $change;
                    $this->log($change);
                } else {
                    $refused = true;
                }
            }

            if (!$refused) {
                return $stored;
            }

            // Another process changed a refund in between: read again and match again. What was
            // stored this round is not matched twice — the transaction is the refund's execution
            // by now, or the refund has its finding.
            if ($stored !== []) {
                $rest = $this->apply($transaction, $account, $network, $now, $closedSince);

                return $rest === null ? null : [...$stored, ...$rest];
            }
        }

        $this->logger->warning('LedgerDirect: a transaction could not be matched against its refunds, they kept changing', [
            'hash' => $transaction->hash,
            'account' => $account,
        ]);

        return null;
    }

    private function log(RefundChange $change): void
    {
        if ($change->isExecution()) {
            $this->logger->info('LedgerDirect: refund executed', [
                'refund' => $change->after->id,
                'hash' => $change->after->executedHash,
                'matched_by' => $change->after->matchedBy,
                'deviation' => implode(',', $change->after->deviations),
            ]);

            return;
        }

        $this->logger->warning('LedgerDirect: refund finding', [
            'refund' => $change->after->id,
            'finding' => $change->finding?->code,
            'hash' => $change->finding?->hash,
            'result' => $change->finding?->result,
        ]);
    }

    /**
     * @return array{cursor?: int, at?: int}
     */
    private function note(string $account, string $network): array
    {
        if ($this->cache === null) {
            return [];
        }

        try {
            $value = $this->cache->get($this->key($account, $network));
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: reading the refund cursor failed, reading from the oldest refund', [
                'account' => $account,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        // Anything else is not a note this code wrote.
        if (!is_array($value) || !is_int($value['cursor'] ?? null) || !is_int($value['at'] ?? null)) {
            return [];
        }

        return ['cursor' => $value['cursor'], 'at' => $value['at']];
    }

    private function remember(string $account, string $network, int $cursor, int $now): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set($this->key($account, $network), ['cursor' => $cursor, 'at' => $now], self::CACHE_TTL_SECONDS);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: remembering the refund cursor failed', [
                'account' => $account,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function key(string $account, string $network): string
    {
        // PSR-16 guarantees keys of 64 characters; the account goes in as a short digest.
        return implode('.', [self::CACHE_KEY_PREFIX, strtolower($this->chain), $network, substr(hash('sha256', $account), 0, 16)]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
