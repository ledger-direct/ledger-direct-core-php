<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Price\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Price\Oracle\KrakenOracle;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real Kraken API. Not part of the default (unit) test run — see
 * README.md for how to run the "integration" suite deliberately.
 */
final class KrakenOracleIntegrationTest extends TestCase
{
    public function testFetchesAPlausibleXrpUsdPrice(): void
    {
        $oracle = new KrakenOracle(new Client(), new HttpFactory());

        $price = $oracle->getCurrentPriceForPair('XRP', 'USD');

        self::assertGreaterThan(0.01, $price);
        self::assertLessThan(100.0, $price);
    }
}
