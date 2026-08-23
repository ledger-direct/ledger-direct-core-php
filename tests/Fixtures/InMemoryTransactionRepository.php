<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LogicException;

/**
 * In-memory TransactionRepositoryInterface for the standalone test harness
 * (CLAUDE.md section 5). Only nextDestinationTagSequence() has real
 * behavior so far, plus a test-only control to script specific sequence
 * values; the rest throw until a future round's tests need them — extend
 * this fake then rather than each service writing its own.
 *
 * @internal
 */
final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var array<string, int> next sequence value per account */
    private array $sequences = [];

    /** @var array<string, list<int>> scripted sequence values per account, consumed first */
    private array $scriptedSequences = [];

    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        if (!empty($this->scriptedSequences[$destinationAccount] ?? [])) {
            return array_shift($this->scriptedSequences[$destinationAccount]);
        }

        $next = $this->sequences[$destinationAccount] ?? 0;
        $this->sequences[$destinationAccount] = $next + 1;

        return $next;
    }

    /**
     * Test control: makes the next calls to nextDestinationTagSequence() for
     * $destinationAccount return these specific values, in order, before
     * falling back to the real incrementing counter — for deterministically
     * testing specific sequence numbers (e.g. forcing
     * DestinationTagsExhaustedException with a value at RANGE_SIZE).
     *
     * @param int[] $sequences
     */
    public function scriptSequences(string $destinationAccount, array $sequences): void
    {
        $this->scriptedSequences[$destinationAccount] = $sequences;
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
