<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Testing;

use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\StellarPaymentRepositoryContractTestCase;

final class InMemoryStellarPaymentRepositoryContractTest extends StellarPaymentRepositoryContractTestCase
{
    protected function repository(): StellarPaymentRepositoryInterface
    {
        return new InMemoryStellarPaymentRepository();
    }
}
