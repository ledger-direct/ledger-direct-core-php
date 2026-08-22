<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Price;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Price\XrpPriceProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real Binance/Coingecko/Kraken APIs. Not part of the default
 * (unit) test run — see README.md for how to run the "integration" suite
 * deliberately.
 */
final class XrpPriceProviderIntegrationTest extends TestCase
{
    public function testFixedOracleSetProducesAPlausibleRate(): void
    {
        $logger = new RecordingLogger();
        $provider = new XrpPriceProvider(new Client(), new HttpFactory(), $logger);

        $rate = $provider->getCurrentExchangeRate('USD', round: true);

        self::assertNotFalse($rate, 'expected at least one oracle in the fixed set to agree');
        self::assertGreaterThan(0.01, $rate);
        self::assertLessThan(100.0, $rate);
    }
}
