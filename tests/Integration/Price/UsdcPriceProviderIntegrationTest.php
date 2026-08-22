<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Price;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Price\UsdcPriceProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real Coingecko API. Not part of the default (unit) test run —
 * see README.md for how to run the "integration" suite deliberately.
 */
final class UsdcPriceProviderIntegrationTest extends TestCase
{
    public function testNonUsdQuoteProducesAPlausibleRate(): void
    {
        $provider = new UsdcPriceProvider(new Client(), new HttpFactory(), new RecordingLogger());

        $rate = $provider->getCurrentExchangeRate('EUR', round: true);

        self::assertNotFalse($rate, 'expected Coingecko to have a USDC/EUR price');
        self::assertGreaterThan(0.5, $rate);
        self::assertLessThan(2.0, $rate);
    }
}
