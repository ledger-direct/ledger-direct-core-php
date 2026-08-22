<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\RlusdPriceProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class RlusdPriceProviderTest extends TestCase
{
    public function testUsdQuoteShortCircuitsToOneWithoutAnyHttpCall(): void
    {
        $client = new FakeHttpClient(); // nothing queued — any HTTP call would throw
        $provider = new RlusdPriceProvider($client, new HttpFactory(), new RecordingLogger());

        self::assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdQuoteGoesThroughCoingeckoAndRoundsToTwoPlaces(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.921}}'));

        $provider = new RlusdPriceProvider($client, new HttpFactory(), new RecordingLogger());

        self::assertSame(0.92, $provider->getCurrentExchangeRate('EUR', round: true));
    }
}
