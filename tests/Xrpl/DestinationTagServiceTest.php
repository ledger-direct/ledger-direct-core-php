<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagsExhaustedException;
use PHPUnit\Framework\TestCase;

final class DestinationTagServiceTest extends TestCase
{
    public function testReturnsATagWithinTheValidRange(): void
    {
        $service = new DestinationTagService(new InMemoryTransactionRepository());

        $tag = $service->generateDestinationTag();

        self::assertGreaterThanOrEqual(10000, $tag);
        self::assertLessThanOrEqual(2140000000, $tag);
    }

    public function testReservesTheTagItReturns(): void
    {
        $repository = new InMemoryTransactionRepository();
        $service = new DestinationTagService($repository);

        $tag = $service->generateDestinationTag();

        self::assertTrue($repository->isDestinationTagReserved($tag));
    }

    public function testRetriesUntilAFreeTagIsFound(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->rejectNextCalls(5); // forces 5 collisions before a real check happens

        $service = new DestinationTagService($repository);

        $tag = $service->generateDestinationTag();

        self::assertGreaterThanOrEqual(10000, $tag);
        self::assertLessThanOrEqual(2140000000, $tag);
    }

    public function testThrowsWhenAttemptsAreExhausted(): void
    {
        $repository = new InMemoryTransactionRepository();
        $repository->reserveEverything();

        $service = new DestinationTagService($repository);

        $this->expectException(DestinationTagsExhaustedException::class);

        $service->generateDestinationTag();
    }
}
