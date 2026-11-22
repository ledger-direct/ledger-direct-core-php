<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Testing;

use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryRefundRepository;
use Hardcastle\LedgerDirect\Core\Testing\RefundRepositoryContractTestCase;

/**
 * The same contract for Xahau's table: the native asset reads back as XAH's, not XRP's.
 */
final class InMemoryXahauRefundRepositoryContractTest extends RefundRepositoryContractTestCase
{
    protected function repository(): RefundRepositoryInterface
    {
        return new InMemoryRefundRepository('XAHAU');
    }

    protected function chain(): string
    {
        return 'XAHAU';
    }

    public function testTheNativeAssetIsStoredUnderXahausName(): void
    {
        $repository = new InMemoryRefundRepository('XAHAU');
        $repository->insert($this->refund(1));

        self::assertSame('XAH', $repository->storedRows()[0]['asset_currency']);
        self::assertNull($repository->storedRows()[0]['asset_issuer']);
        self::assertSame('XAHAU', $repository->find($this->refund(1)->id)?->chain);
    }
}
