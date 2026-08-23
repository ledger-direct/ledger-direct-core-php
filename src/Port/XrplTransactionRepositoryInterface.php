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
     * $destinationAccount, starting at 0 the first time this is called for
     * a given account (1 the second time, etc). DestinationTagService turns
     * this into the actual destination tag via a fixed bijective
     * permutation — this method only needs to guarantee the sequence
     * itself never repeats for that account.
     *
     * MUST be atomic under concurrent calls for the same account — e.g. a
     * per-account counter row updated via the platform DB's native atomic
     * increment (MySQL: `INSERT ... ON DUPLICATE KEY UPDATE counter =
     * counter + 1`), not a select-then-update pair. Unlike a raw random
     * value, an atomic counter has no collision to check for, so there's no
     * check-then-act race here the way there used to be.
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

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction;

    /**
     * @return string|null the highest synced ledger_index, or null if none synced yet
     */
    public function getLastSyncedLedgerIndex(): ?string;

    public function truncate(): void;
}
