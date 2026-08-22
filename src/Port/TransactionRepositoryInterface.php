<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Port;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;

/**
 * Provided by the platform. Persistence for `ledger_direct_xrpl_tx` and
 * `ledger_direct_xrpl_destination_tag` (see INVARIANTS.md). Storage
 * primitives only — destination-tag generation and sync/dedup orchestration
 * are core-side service behavior, not the platform's concern.
 */
interface TransactionRepositoryInterface
{
    public function isDestinationTagReserved(int $destinationTag): bool;

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
