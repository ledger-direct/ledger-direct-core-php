<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Testing;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\XrplTransactionRepositoryContractTestCase;

/**
 * The core's own reference repository passes the contract suite adapters
 * extend — which is what keeps the suite honest.
 */
final class InMemoryXrplTransactionRepositoryContractTest extends XrplTransactionRepositoryContractTestCase
{
    protected function repository(): XrplTransactionRepositoryInterface
    {
        return new InMemoryXrplTransactionRepository();
    }
}
