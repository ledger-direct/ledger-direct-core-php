<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagsExhaustedException;
use PHPUnit\Framework\TestCase;

final class DestinationTagServiceTest extends TestCase
{
    private const ACCOUNT_A = 'rAccountA';

    private const ACCOUNT_B = 'rAccountB';

    /** first sequence value beyond the account's usable range (RANGE_SIZE = 4294967295 - 10000 + 1) */
    private const RANGE_SIZE = 4294957296;

    public function testGivenSequenceAlwaysMapsToTheSameTag(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_A, [0]);
        $service = new DestinationTagService($repository);

        // Deterministic: verified independently via `php -r` before writing this assertion.
        self::assertSame(114729, $service->generateDestinationTag(self::ACCOUNT_A));
    }

    public function testDistinctSequencesMapToDistinctTags(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $service = new DestinationTagService($repository);

        $tags = [];
        for ($i = 0; $i < 10000; $i++) {
            $tags[] = $service->generateDestinationTag(self::ACCOUNT_A);
        }

        self::assertCount(10000, array_unique($tags));
    }

    public function testEveryGeneratedTagIsWithinTheValidRange(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $service = new DestinationTagService($repository);

        for ($i = 0; $i < 1000; $i++) {
            $tag = $service->generateDestinationTag(self::ACCOUNT_A);

            self::assertGreaterThanOrEqual(10000, $tag);
            self::assertLessThanOrEqual(4294967295, $tag);
        }
    }

    public function testForwardsTheAccountToNextDestinationTagSequence(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_B, [42]);
        $service = new DestinationTagService($repository);

        // Deterministic: verified independently via `php -r` before writing this assertion.
        self::assertSame(4110940623, $service->generateDestinationTag(self::ACCOUNT_B));
    }

    /**
     * The F1 regression. With the counter fixed at 0, the first order of
     * *every* installation on a shared receiving account got tag 114729 —
     * so on the shared testnet account, one shop's payment settled
     * another's order (WooCommerce #133). A random start is what breaks
     * that alignment.
     *
     * Phrased as "20 fresh installations do not all agree" rather than
     * "two differ" so it cannot flake: two 31-bit starts collide once in
     * 2^31, twenty identical ones are not going to happen.
     */
    public function testFreshInstallationsOnTheSameAccountDoNotAllIssueTheSameFirstTag(): void
    {
        $firstTags = [];

        for ($i = 0; $i < 20; $i++) {
            $service = new DestinationTagService(new InMemoryXrplTransactionRepository());
            $firstTags[] = $service->generateDestinationTag(self::ACCOUNT_A);
        }

        self::assertGreaterThan(1, count(array_unique($firstTags)));
    }

    public function testTheSequenceStartsInRangeAndThenIncrementsByOne(): void
    {
        $repository = new InMemoryXrplTransactionRepository();

        $first = $repository->nextDestinationTagSequence(self::ACCOUNT_A);

        self::assertGreaterThanOrEqual(0, $first);
        self::assertLessThanOrEqual(2147483647, $first);
        self::assertSame($first + 1, $repository->nextDestinationTagSequence(self::ACCOUNT_A));
        self::assertSame($first + 2, $repository->nextDestinationTagSequence(self::ACCOUNT_A));
    }

    /**
     * A random start must not eat the range: the guard still has to be
     * reachable, and ~2.1 billion sequences must remain after the highest
     * permitted start.
     */
    public function testTheHighestPermittedStartStillLeavesBillionsOfSequences(): void
    {
        self::assertGreaterThan(2_000_000_000, self::RANGE_SIZE - 2147483647);
    }

    public function testThrowsWhenTheAccountsSequenceIsExhausted(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_A, [self::RANGE_SIZE]);
        $service = new DestinationTagService($repository);

        $this->expectException(DestinationTagsExhaustedException::class);

        $service->generateDestinationTag(self::ACCOUNT_A);
    }
}
