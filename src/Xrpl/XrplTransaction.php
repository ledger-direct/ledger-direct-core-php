<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

/**
 * A synced XRPL transaction, as persisted by the platform through
 * {@see \Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface}.
 * Mirrors the `ledger_direct_xrpl_tx` table (see INVARIANTS.md), minus the
 * storage-generated primary key.
 */
final readonly class XrplTransaction
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $tx
     */
    public function __construct(
        public string $ledgerIndex,
        public string $hash,
        public string $ctid,
        public string $account,
        public string $destination,
        public ?int $destinationTag,
        public int $date,
        public array $meta,
        public array $tx,
    ) {
    }
}
