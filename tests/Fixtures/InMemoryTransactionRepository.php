<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;

/**
 * In-memory TransactionRepositoryInterface for the standalone test harness
 * (CLAUDE.md section 5).
 *
 * @internal
 */
final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var array<string, XrplTransaction> stored transactions, keyed by hash */
    private array $transactionsByHash = [];

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
        return array_values(array_intersect($hashes, array_keys($this->transactionsByHash)));
    }

    public function saveTransactions(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            $this->transactionsByHash[$transaction->hash] = $transaction;
        }
    }

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        foreach ($this->transactionsByHash as $transaction) {
            if ($transaction->destination === $destination && $transaction->destinationTag === $destinationTag) {
                return $transaction;
            }
        }

        return null;
    }

    public function getLastSyncedLedgerIndex(): ?string
    {
        if ($this->transactionsByHash === []) {
            return null;
        }

        $max = null;
        foreach ($this->transactionsByHash as $transaction) {
            if ($max === null || (int) $transaction->ledgerIndex > (int) $max) {
                $max = $transaction->ledgerIndex;
            }
        }

        return $max;
    }

    public function truncate(): void
    {
        $this->transactionsByHash = [];
    }

    /**
     * Test control: exposes what's stored, for assertions.
     *
     * @return XrplTransaction[]
     */
    public function storedTransactions(): array
    {
        return array_values($this->transactionsByHash);
    }
}
