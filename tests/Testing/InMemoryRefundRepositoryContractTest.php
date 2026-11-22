<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Testing;

use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryRefundRepository;
use Hardcastle\LedgerDirect\Core\Testing\RefundRepositoryContractTestCase;

/**
 * The core's own reference repository passes the contract suite adapters extend.
 */
final class InMemoryRefundRepositoryContractTest extends RefundRepositoryContractTestCase
{
    protected function repository(): RefundRepositoryInterface
    {
        return new InMemoryRefundRepository();
    }

    /**
     * The reference keeps its rows as a database driver returns them: every integer a string.
     */
    public function testTheReferenceRepositoryStoresRowsAsADatabaseReturnsThem(): void
    {
        $repository = new InMemoryRefundRepository();
        $repository->insert($this->refund(1));

        $row = $repository->storedRows()[0];

        self::assertSame('1', $row['version']);
        self::assertSame('4711', $row['destination_tag']);
        self::assertSame('22475000', $row['created_ledger']);
        self::assertNull($row['executed_hash']);
    }
}
