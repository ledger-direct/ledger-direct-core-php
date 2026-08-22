<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Price\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Price\Oracle\BinanceOracle;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real Binance API. Not part of the default (unit) test run — see
 * README.md for how to run the "integration" suite deliberately.
 */
final class BinanceOracleIntegrationTest extends TestCase
{
    public function testFetchesAPlausibleXrpUsdPrice(): void
    {
        $oracle = new BinanceOracle(new Client(), new HttpFactory());

        $price = $oracle->getCurrentPriceForPair('XRP', 'USD');

        self::assertGreaterThan(0.01, $price);
        self::assertLessThan(100.0, $price);
    }
}
