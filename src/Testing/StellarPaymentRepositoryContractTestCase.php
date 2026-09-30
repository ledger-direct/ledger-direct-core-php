<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use PHPUnit\Framework\TestCase;

/**
 * The contract every StellarPaymentRepositoryInterface implementation has
 * to keep, as a test an adapter extends — the sibling of
 * XrplTransactionRepositoryContractTestCase. Newest-first with a total
 * tie-break, a cursor scoped by account *and* network, a counter that
 * starts at a random offset and never at 0, dedup by (hash, op_index).
 * The core's InMemoryStellarPaymentRepository passes this suite.
 */
abstract class StellarPaymentRepositoryContractTestCase extends TestCase
{
    /** A fresh, empty repository for each test. */
    abstract protected function repository(): StellarPaymentRepositoryInterface;

    protected function payment(
        string $hash,
        string $paymentId,
        int $opIndex = 1,
        string $destination = 'GDEST',
        ?string $memoId = '123456',
        string $network = 'testnet',
    ): StellarPayment {
        return new StellarPayment(
            network: $network,
            paymentId: $paymentId,
            hash: $hash,
            opIndex: $opIndex,
            type: 'payment',
            account: 'GSENDER',
            destination: $destination,
            memoId: $memoId,
            assetCode: StellarPayment::ASSET_NATIVE,
            assetIssuer: null,
            amount: '1.0000000',
            createdAt: 1790169372,
            raw: [],
        );
    }

    public function testFindPaymentsReturnsNewestFirstAndOnlyForThePair(): void
    {
        $repository = $this->repository();
        $repository->savePayments([
            $this->payment('HASH_OLD', '100'),
            $this->payment('HASH_NEW', '900'),
            $this->payment('HASH_OTHER_MEMO', '950', memoId: '2'),
            $this->payment('HASH_NO_MEMO', '960', memoId: null),
            $this->payment('HASH_OTHER_DEST', '970', destination: 'GELSEWHERE'),
        ]);

        self::assertSame(['HASH_NEW', 'HASH_OLD'], $this->hashes($repository->findPayments('GDEST', '123456')));
        self::assertSame([], $repository->findPayments('GDEST', '4242'));
    }

    public function testFindPaymentsBreaksAPaymentIdTieByStorageOrder(): void
    {
        $repository = $this->repository();
        $repository->savePayments([$this->payment('HASH_FIRST', '500')]);
        $repository->savePayments([$this->payment('HASH_SECOND', '500')]);

        self::assertSame(['HASH_SECOND', 'HASH_FIRST'], $this->hashes($repository->findPayments('GDEST', '123456')));
    }

    /**
     * Two operations of one transaction are two rows: same hash, different
     * op_index. And the same pair stored twice is one row.
     */
    public function testTheIdentityIsHashAndOpIndex(): void
    {
        $repository = $this->repository();
        $repository->savePayments([
            $this->payment('HASH_TX', '20741084267155457', opIndex: 1),
            $this->payment('HASH_TX', '20741084267155458', opIndex: 2),
        ]);

        self::assertCount(2, $repository->findPayments('GDEST', '123456'));
        self::assertSame(['HASH_TX:1', 'HASH_TX:2'], $repository->findExistingKeys(['HASH_TX:1', 'HASH_TX:2', 'HASH_TX:3', 'OTHER:1']));
        self::assertSame([], $repository->findExistingKeys(['OTHER:1']));
    }

    public function testTheCursorIsScopedByAccountAndNetwork(): void
    {
        $repository = $this->repository();
        $repository->savePayments([
            $this->payment('HASH_MAINNET', '999999999999', network: 'mainnet'),
            $this->payment('HASH_TESTNET', '500', network: 'testnet'),
            $this->payment('HASH_OTHER_ACCOUNT', '900000', destination: 'GPREVIOUS'),
        ]);

        self::assertSame('500', $repository->getLastPaymentId('GDEST', 'testnet'));
        self::assertSame('999999999999', $repository->getLastPaymentId('GDEST', 'mainnet'));
        self::assertNull($repository->getLastPaymentId('GDEST', 'futurenet'));
        self::assertNull($repository->getLastPaymentId('GNEVERSEEN', 'testnet'));
    }

    /**
     * The cursor compares numerically, not lexically: "9" is below "10".
     */
    public function testTheCursorIsTheNumericallyHighestPaymentId(): void
    {
        $repository = $this->repository();
        $repository->savePayments([
            $this->payment('HASH_A', '9'),
            $this->payment('HASH_B', '10'),
        ]);

        self::assertSame('10', $repository->getLastPaymentId('GDEST', 'testnet'));
    }

    public function testTheMemoIdSequenceStartsRandomlyAndCountsUp(): void
    {
        $repository = $this->repository();

        $first = $repository->nextMemoIdSequence('GDEST');
        self::assertGreaterThanOrEqual(0, $first);
        self::assertLessThanOrEqual(2 ** 31 - 1, $first);
        self::assertSame($first + 1, $repository->nextMemoIdSequence('GDEST'));

        $other = $repository->nextMemoIdSequence('GOTHER');
        self::assertSame($other + 1, $repository->nextMemoIdSequence('GOTHER'));
        self::assertSame($first + 2, $repository->nextMemoIdSequence('GDEST'), 'accounts do not share a counter');
    }

    public function testTheMemoIdSequenceDoesNotStartAtZero(): void
    {
        $starts = [];
        for ($i = 0; $i < 20; $i++) {
            $starts[] = $this->repository()->nextMemoIdSequence('GDEST');
        }

        self::assertNotContains(0, $starts);
        self::assertGreaterThan(1, count(array_unique($starts)));
    }

    public function testTruncateEmptiesThePaymentTable(): void
    {
        $repository = $this->repository();
        $repository->savePayments([$this->payment('HASH_A', '1')]);

        $repository->truncate();

        self::assertSame([], $repository->findPayments('GDEST', '123456'));
        self::assertNull($repository->getLastPaymentId('GDEST', 'testnet'));
    }

    /**
     * @param list<StellarPayment> $payments
     * @return list<string>
     */
    private function hashes(array $payments): array
    {
        return array_map(static fn (StellarPayment $p): string => $p->hash, $payments);
    }
}
