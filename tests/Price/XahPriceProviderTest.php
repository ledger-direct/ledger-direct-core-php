<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\Oracle\BitrueOracle;
use Hardcastle\LedgerDirect\Core\Price\XahPriceProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * XAH trades on one market, Bitrue. Coingecko reports it; Bitrue is asked
 * directly. Two feeds of one market, read like every oracle set: averaged
 * while they agree, no price when they do not — which with two feeds means
 * a spread of about ten percent, since each is measured against their mean.
 */
final class XahPriceProviderTest extends TestCase
{
    public function testAveragesCoingeckoAndBitrueAndAsksThemForXah(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"xahau":{"usd":0.0100}}'));
        $client->queueResponse('openapi.bitrue.com', new Response(200, [], '{"symbol":"XAHUSDT","price":"0.0102"}'));

        $rate = (new XahPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(0.0101, $rate, 1e-9);

        $urls = array_map(static fn ($r): string => (string) $r->getUri(), $client->sentRequests());
        self::assertStringContainsString('ids=xahau&vs_currencies=usd', $urls[0]);
        self::assertStringContainsString('ticker/price?symbol=XAHUSDT', $urls[1]);
        self::assertCount(2, $urls, 'no Binance, no Kraken: neither lists XAH');
    }

    public function testTwoFeedsSixPercentApartAreStillAveraged(): void
    {
        $client = new FakeHttpClient();
        // 0.0100 against 0.0106: each sits under 3 % off their mean of 0.0103, so both pass.
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"xahau":{"usd":0.0100}}'));
        $client->queueResponse('openapi.bitrue.com', new Response(200, [], '{"symbol":"XAHUSDT","price":"0.0106"}'));

        self::assertEqualsWithDelta(0.0103, (new XahPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD'), 1e-9);
    }

    public function testTwoFeedsThatDisagreeGiveNoPrice(): void
    {
        $client = new FakeHttpClient();
        // 0.0100 against 0.0125: both sit more than 5 % off their average.
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"xahau":{"usd":0.0100}}'));
        $client->queueResponse('openapi.bitrue.com', new Response(200, [], '{"symbol":"XAHUSDT","price":"0.0125"}'));

        self::assertFalse((new XahPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD'));
    }

    public function testEuroComesFromCoingeckoAloneBecauseBitrueQuotesUsdtOnly(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"xahau":{"eur":0.0091}}'));

        $rate = (new XahPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('EUR');

        self::assertEqualsWithDelta(0.0091, $rate, 1e-9);
        self::assertCount(1, $client->sentRequests(), 'no call to Bitrue for EUR');
    }

    public function testOneFeedDownLeavesTheOther(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(500));
        $client->queueResponse('openapi.bitrue.com', new Response(200, [], '{"symbol":"XAHUSDT","price":"0.0102"}'));

        $rate = (new XahPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(0.0102, $rate, 1e-9);
    }

    public function testBitrueAnswersZeroForAnUnknownSymbol(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('openapi.bitrue.com', new Response(412, [], '{"code":-1121,"msg":"Invalid symbol.","data":null}'));

        self::assertSame(0.0, (new BitrueOracle($client, new HttpFactory()))->getCurrentPriceForPair('FOO', 'USD'));
    }
}
