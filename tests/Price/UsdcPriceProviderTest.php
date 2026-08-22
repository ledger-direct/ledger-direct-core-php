<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\UsdcPriceProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class UsdcPriceProviderTest extends TestCase
{
    public function testUsdQuoteShortCircuitsToOneWithoutAnyHttpCall(): void
    {
        $client = new FakeHttpClient(); // nothing queued — any HTTP call would throw
        $provider = new UsdcPriceProvider($client, new HttpFactory(), new RecordingLogger());

        self::assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdQuoteGoesThroughCoingeckoAndRoundsToTwoPlaces(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"usd-coin":{"eur":0.919}}'));

        $provider = new UsdcPriceProvider($client, new HttpFactory(), new RecordingLogger());

        self::assertSame(0.92, $provider->getCurrentExchangeRate('EUR', round: true));
    }
}
