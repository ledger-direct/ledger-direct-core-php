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
 * `destination_tag` must be an **unsigned** 32-bit integer column (XRPL's
 * DestinationTag field's true range is 0–4294967295) — a signed 32-bit
 * column overflows above 2147483647, silently truncating/rejecting values
 * DestinationTagService can legitimately generate. See INVARIANTS.md.
 */
interface TransactionRepositoryInterface
{
    public function isDestinationTagReserved(int $destinationTag): bool;

    /**
     * Called only after DestinationTagService has checked
     * isDestinationTagReserved() itself — check-then-reserve, not atomic.
     * Two concurrent calls can both pass that check for the same tag before
     * either reserves it; a real but very-low-probability race given the
     * ~4.29 billion-value range and that a reservation is a single fast
     * write. Known and accepted, not fixed by redesigning this into a
     * reserve-or-throw contract.
     */
    public function reserveDestinationTag(int $destinationTag): void;

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
