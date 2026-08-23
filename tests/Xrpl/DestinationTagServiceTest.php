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

    public function testReturnsATagWithinTheValidRange(): void
    {
        $service = new DestinationTagService(new InMemoryTransactionRepository());

        $tag = $service->generateDestinationTag(self::ACCOUNT_A);

        self::assertGreaterThanOrEqual(10000, $tag);
        self::assertLessThanOrEqual(4294967295, $tag);
    }

    public function testReservesTheTagItReturnsForThatAccount(): void
    {
        $repository = new InMemoryTransactionRepository();
        $service = new DestinationTagService($repository);

        $tag = $service->generateDestinationTag(self::ACCOUNT_A);

        self::assertTrue($repository->isDestinationTagReserved(self::ACCOUNT_A, $tag));
    }

    public function testReservationIsScopedPerAccount(): void
    {
        $repository = new InMemoryTransactionRepository();
        $service = new DestinationTagService($repository);

        $tag = $service->generateDestinationTag(self::ACCOUNT_A);

        // The same tag is still free for a different account.
        self::assertFalse($repository->isDestinationTagReserved(self::ACCOUNT_B, $tag));
    }

    public function testRetriesUntilAFreeTagIsFound(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->rejectNextCalls(5); // forces 5 collisions before a real check happens

        $service = new DestinationTagService($repository);

        $tag = $service->generateDestinationTag(self::ACCOUNT_A);

        self::assertGreaterThanOrEqual(10000, $tag);
        self::assertLessThanOrEqual(4294967295, $tag);
    }

    public function testThrowsWhenAttemptsAreExhausted(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->reserveEverything();

        $service = new DestinationTagService($repository);

        $this->expectException(DestinationTagsExhaustedException::class);

        $service->generateDestinationTag(self::ACCOUNT_A);
    }
}
