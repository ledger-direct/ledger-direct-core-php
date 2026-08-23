<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryTransactionRepository;
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
        $repository = new InMemoryTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_A, [0]);
        $service = new DestinationTagService($repository);

        // Deterministic: verified independently via `php -r` before writing this assertion.
        self::assertSame(114729, $service->generateDestinationTag(self::ACCOUNT_A));
    }

    public function testDistinctSequencesMapToDistinctTags(): void
    {
        $repository = new InMemoryTransactionRepository();
        $service = new DestinationTagService($repository);

        $tags = [];
        for ($i = 0; $i < 10000; $i++) {
            $tags[] = $service->generateDestinationTag(self::ACCOUNT_A);
        }

        self::assertCount(10000, array_unique($tags));
    }

    public function testEveryGeneratedTagIsWithinTheValidRange(): void
    {
        $repository = new InMemoryTransactionRepository();
        $service = new DestinationTagService($repository);

        for ($i = 0; $i < 1000; $i++) {
            $tag = $service->generateDestinationTag(self::ACCOUNT_A);

            self::assertGreaterThanOrEqual(10000, $tag);
            self::assertLessThanOrEqual(4294967295, $tag);
        }
    }

    public function testForwardsTheAccountToNextDestinationTagSequence(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_B, [42]);
        $service = new DestinationTagService($repository);

        // Deterministic: verified independently via `php -r` before writing this assertion.
        self::assertSame(4110940623, $service->generateDestinationTag(self::ACCOUNT_B));
    }

    public function testThrowsWhenTheAccountsSequenceIsExhausted(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->scriptSequences(self::ACCOUNT_A, [self::RANGE_SIZE]);
        $service = new DestinationTagService($repository);

        $this->expectException(DestinationTagsExhaustedException::class);

        $service->generateDestinationTag(self::ACCOUNT_A);
    }
}
