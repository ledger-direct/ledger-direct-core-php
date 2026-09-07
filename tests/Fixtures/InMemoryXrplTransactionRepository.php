<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;

/**
 * In-memory XrplTransactionRepositoryInterface for the standalone test
 * harness (CLAUDE.md section 5).
 *
 * @internal
 */
final class InMemoryXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    /** @var array<string, XrplTransaction> stored transactions, keyed by hash */
    private array $transactionsByHash = [];

    /** @var array<string, int> next sequence value per account */
    private array $sequences = [];

    /** @var array<string, list<int>> scripted sequence values per account, consumed first */
    private array $scriptedSequences = [];

    /**
     * Upper bound for the counter's random start, per the port contract —
     * low enough that ~2.1 billion sequences remain before exhaustion.
     */
    private const MAX_RANDOM_START = 2147483647;

    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        if (!empty($this->scriptedSequences[$destinationAccount] ?? [])) {
            return array_shift($this->scriptedSequences[$destinationAccount]);
        }

        // Random start, not 0 — see the port contract. This fixture is the
        // core's reference implementation of that rule.
        if (!isset($this->sequences[$destinationAccount])) {
            $this->sequences[$destinationAccount] = random_int(0, self::MAX_RANDOM_START);
        }

        return $this->sequences[$destinationAccount]++;
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

    public function findTransactions(string $destination, int $destinationTag): array
    {
        $matches = array_values(array_filter(
            $this->transactionsByHash,
            static fn (XrplTransaction $t): bool
                => $t->destination === $destination && $t->destinationTag === $destinationTag,
        ));

        /*
         * The port promises ledger_index DESC, tie-broken by primary key
         * DESC. PHP's sort is stable, so reversing insertion order first
         * makes the most recently saved row win a tie — this fixture's
         * stand-in for "highest id".
         */
        $matches = array_reverse($matches);

        usort(
            $matches,
            static fn (XrplTransaction $a, XrplTransaction $b): int
                => (int) $b->ledgerIndex <=> (int) $a->ledgerIndex,
        );

        return $matches;
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
