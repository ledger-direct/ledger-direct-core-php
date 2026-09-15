<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;

/**
 * The contract every XrplTransactionRepositoryInterface implementation has
 * to keep — as a test an adapter extends. The rules are subtle (newest-first
 * with a total tie-break, a cursor scoped by account *and* network, a
 * counter that starts at a random offset and never at 0) and each of them
 * once cost an adapter a mis-settled or stranded order; see INVARIANTS.md,
 * "Tables". The core's own InMemoryXrplTransactionRepository passes this
 * suite, which is what keeps the suite honest.
 *
 * Needs PHPUnit, which the core lists under `suggest`, not `require`: this
 * class is only ever loaded by a test that extends it.
 */
abstract class XrplTransactionRepositoryContractTestCase extends TestCase
{
    /** A fresh, empty repository for each test. */
    abstract protected function repository(): XrplTransactionRepositoryInterface;

    /**
     * Builds a transaction the repository can store. Adapters override this
     * only if their storage needs something the defaults do not provide.
     */
    protected function transaction(
        string $hash,
        string $ledgerIndex,
        string $destination = 'rDest',
        ?int $destinationTag = 1,
        string $network = 'testnet',
    ): XrplTransaction {
        return new XrplTransaction(
            network: $network,
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'C0000000000000000000000',
            account: 'rSender',
            destination: $destination,
            destinationTag: $destinationTag,
            date: 800000000,
            meta: [],
            tx: [],
        );
    }

    public function testFindTransactionsReturnsNewestFirstAndOnlyForThePair(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([
            $this->transaction('HASH_OLD', '100'),
            $this->transaction('HASH_NEW', '900'),
            $this->transaction('HASH_OTHER_TAG', '950', destinationTag: 2),
            $this->transaction('HASH_OTHER_DEST', '960', destination: 'rElsewhere'),
        ]);

        self::assertSame(['HASH_NEW', 'HASH_OLD'], $this->hashes($repository->findTransactions('rDest', 1)));
        self::assertSame([], $repository->findTransactions('rDest', 4242));
    }

    /**
     * Two rows on the same ledger index: the one stored later wins the tie,
     * so the order is total and "newest" never depends on scan order.
     */
    public function testFindTransactionsBreaksALedgerIndexTieByStorageOrder(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([$this->transaction('HASH_FIRST', '500')]);
        $repository->saveTransactions([$this->transaction('HASH_SECOND', '500')]);

        self::assertSame(['HASH_SECOND', 'HASH_FIRST'], $this->hashes($repository->findTransactions('rDest', 1)));
    }

    public function testTheCursorIsScopedByAccountAndNetwork(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([
            $this->transaction('HASH_MAINNET', '100000000', network: 'mainnet'),
            $this->transaction('HASH_TESTNET', '500', network: 'testnet'),
            $this->transaction('HASH_OTHER_ACCOUNT', '900000', destination: 'rPreviousAddress'),
        ]);

        self::assertSame('500', $repository->getLastSyncedLedgerIndex('rDest', 'testnet'));
        self::assertSame('100000000', $repository->getLastSyncedLedgerIndex('rDest', 'mainnet'));
        self::assertNull($repository->getLastSyncedLedgerIndex('rDest', 'devnet'));
        self::assertNull($repository->getLastSyncedLedgerIndex('rNeverSeen', 'testnet'));
    }

    public function testFindExistingHashesReturnsOnlyTheStoredSubset(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([$this->transaction('HASH_A', '1'), $this->transaction('HASH_B', '2')]);

        $found = $repository->findExistingHashes(['HASH_A', 'HASH_UNKNOWN', 'HASH_B']);
        sort($found);

        self::assertSame(['HASH_A', 'HASH_B'], $found);
        self::assertSame([], $repository->findExistingHashes(['HASH_UNKNOWN']));
    }

    /**
     * The first value is a fresh random_int(0, 2^31 - 1), never 0; every
     * later call is the previous value plus one; accounts are independent.
     */
    public function testTheDestinationTagSequenceStartsRandomlyAndCountsUp(): void
    {
        $repository = $this->repository();

        $first = $repository->nextDestinationTagSequence('rDest');
        self::assertGreaterThanOrEqual(0, $first);
        self::assertLessThanOrEqual(2 ** 31 - 1, $first);
        self::assertSame($first + 1, $repository->nextDestinationTagSequence('rDest'));
        self::assertSame($first + 2, $repository->nextDestinationTagSequence('rDest'));

        $other = $repository->nextDestinationTagSequence('rOther');
        self::assertSame($other + 1, $repository->nextDestinationTagSequence('rOther'));
        self::assertSame($first + 3, $repository->nextDestinationTagSequence('rDest'), 'accounts do not share a counter');
    }

    public function testTheDestinationTagSequenceDoesNotStartAtZero(): void
    {
        $starts = [];
        for ($i = 0; $i < 20; $i++) {
            $starts[] = $this->repository()->nextDestinationTagSequence('rDest');
        }

        self::assertNotContains(0, $starts);
        self::assertGreaterThan(1, count(array_unique($starts)), 'a fixed start makes every installation issue the same tags');
    }

    public function testTruncateEmptiesTheTransactionTable(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([$this->transaction('HASH_A', '1')]);

        $repository->truncate();

        self::assertSame([], $repository->findTransactions('rDest', 1));
        self::assertNull($repository->getLastSyncedLedgerIndex('rDest', 'testnet'));
    }

    /**
     * @param XrplTransaction[] $transactions
     * @return list<string>
     */
    private function hashes(array $transactions): array
    {
        return array_map(static fn (XrplTransaction $t): string => $t->hash, $transactions);
    }
}
