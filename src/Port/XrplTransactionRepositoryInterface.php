<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Port;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;

/**
 * Provided by the platform. Persistence for `ledger_direct_xrpl_tx` and
 * `ledger_direct_xrpl_destination_tag` (see INVARIANTS.md). Storage
 * primitives only — destination-tag generation and sync/dedup orchestration
 * are core-side service behavior, not the platform's concern.
 *
 * XRPL-specific by name and shape (XrplTransaction, ledger-index/marker
 * pagination) — deliberately not a generalized multi-chain interface. A
 * future second chain (e.g. Stellar) gets its own sibling port with its own
 * transaction/pagination shape, not a forced fit into this one.
 *
 * `destination_tag` must be an **unsigned** 32-bit integer column (XRPL's
 * DestinationTag field's true range is 0–4294967295) — a signed 32-bit
 * column overflows above 2147483647, silently truncating/rejecting values
 * DestinationTagService can legitimately generate. See INVARIANTS.md.
 */
interface XrplTransactionRepositoryInterface
{
    /**
     * Returns a number that is unique and strictly increasing per
     * $destinationAccount. DestinationTagService turns it into the actual
     * destination tag via a fixed bijective permutation — this method only
     * needs to guarantee the sequence itself never repeats for that account.
     *
     * The first value for an account MUST be a fresh
     * `random_int(0, 2 ** 31 - 1)`, drawn once when the counter row is
     * created; every later call returns the previous value plus one. It
     * MUST NOT start at 0.
     *
     * Why random rather than 0: the permutation's multiplier and offset are
     * public constants, so a fixed start makes the tag sequence identical
     * everywhere. Two installations sharing one receiving account — a
     * merchant running two platforms on one wallet, or a test setup — then
     * hand out the same tags, and one shop's payment settles the other's
     * order. Dropping and recreating the counter table does the same thing
     * to the installation's own past orders. A random start is what breaks
     * that alignment. It also stops anyone inverting a tag they were given
     * into the merchant's running order count.
     *
     * The upper bound is deliberate: starting no higher than 2^31-1 leaves
     * at least ~2.1 billion sequences before DestinationTagsExhaustedException,
     * so the exhaustion guard stays meaningful and no wraparound is needed.
     *
     * MUST be atomic under concurrent calls for the same account — e.g. a
     * per-account counter row updated via the platform DB's native atomic
     * increment (MySQL: `INSERT ... VALUES (:account, LAST_INSERT_ID(:random))
     * ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)`),
     * not a select-then-update pair. Unlike a raw random value per tag, an
     * atomic counter has no collision to check for, so there's no
     * check-then-act race here the way there used to be.
     *
     * The counter table MUST survive plugin uninstall. It is the only
     * record that a tag was already issued; dropping it re-issues tags that
     * still-open orders are waiting on.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int;

    /**
     * @param string[] $hashes
     * @return string[] the subset of $hashes already stored
     */
    public function findExistingHashes(array $hashes): array;

    /**
     * @param XrplTransaction[] $transactions
     */
    public function saveTransactions(array $transactions): void;

    /**
     * Every transaction stored for this destination account and tag,
     * **newest first**: `ORDER BY ledger_index DESC`, tie-broken by the
     * storage primary key descending so the order is total.
     *
     * Plural on purpose. A single tag can legitimately carry more than one
     * transaction — a stray payment in the wrong asset, a payment that
     * predates the order, two partial payments — and returning whichever
     * row the primary key happened to surface first is what let a foreign
     * payment settle someone else's order. **Which** of the candidates
     * fulfills a given intent is a core decision
     * ({@see \Hardcastle\LedgerDirect\Core\Xrpl\SyncService::findTransactionFor()}),
     * not a storage one, so this method filters by nothing but the pair.
     *
     * @return XrplTransaction[] newest first; empty when nothing matches
     */
    public function findTransactions(string $destination, int $destinationTag): array;

    /**
     * @return string|null the highest synced ledger_index, or null if none synced yet
     */
    public function getLastSyncedLedgerIndex(): ?string;

    public function truncate(): void;
}
