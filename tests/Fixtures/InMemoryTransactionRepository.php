<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LogicException;

/**
 * In-memory TransactionRepositoryInterface for the standalone test harness
 * (CLAUDE.md section 5). Only the destination-tag methods have real
 * behavior so far, plus test-only controls to force collisions
 * deterministically; the rest throw until a future round's tests need
 * them — extend this fake then rather than each service writing its own.
 *
 * @internal
 */
final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var array<string, array<int, true>> */
    private array $reservedDestinationTags = [];

    private bool $reserveEverything = false;

    private int $rejectNextCalls = 0;

    public function isDestinationTagReserved(string $destinationAccount, int $destinationTag): bool
    {
        if ($this->reserveEverything) {
            return true;
        }

        if ($this->rejectNextCalls > 0) {
            $this->rejectNextCalls--;

            return true;
        }

        return isset($this->reservedDestinationTags[$destinationAccount][$destinationTag]);
    }

    public function reserveDestinationTag(string $destinationAccount, int $destinationTag): void
    {
        $this->reservedDestinationTags[$destinationAccount][$destinationTag] = true;
    }

    /**
     * Test control: makes isDestinationTagReserved() report "reserved" for
     * every tag, unconditionally — for forcing DestinationTagsExhaustedException.
     */
    public function reserveEverything(): void
    {
        $this->reserveEverything = true;
    }

    /**
     * Test control: makes the next $count calls to isDestinationTagReserved()
     * report "reserved" regardless of which tag is asked about, then falls
     * back to real behavior — for deterministically proving a retry loop
     * without depending on random_int()'s actual output.
     */
    public function rejectNextCalls(int $count): void
    {
        $this->rejectNextCalls = $count;
    }

    public function findExistingHashes(array $hashes): array
    {
        throw new LogicException(__METHOD__ . ' not implemented in this fake yet.');
    }

    public function saveTransactions(array $transactions): void
    {
        throw new LogicException(__METHOD__ . ' not implemented in this fake yet.');
    }

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        throw new LogicException(__METHOD__ . ' not implemented in this fake yet.');
    }

    public function getLastSyncedLedgerIndex(): ?string
    {
        throw new LogicException(__METHOD__ . ' not implemented in this fake yet.');
    }

    public function truncate(): void
    {
        throw new LogicException(__METHOD__ . ' not implemented in this fake yet.');
    }
}
