<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Sync;

/**
 * "Pull this receiving account's incoming payments from the ledger into
 * storage" — the one thing SyncThrottle needs to know about a chain's sync
 * service. Implemented by Xrpl\SyncService and Stellar\SyncService; not a
 * platform port.
 */
interface LedgerSyncInterface
{
    public function sync(string $account, string $network): void;
}
