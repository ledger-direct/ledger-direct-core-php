<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\XlmPriceProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class XlmPriceProviderTest extends TestCase
{
    public function testAveragesTheThreeOraclesAndAsksThemForXlm(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.40"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"stellar":{"usd":0.41}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXLMZUSD":{"c":["0.42","100"]}}}'));

        $rate = (new XlmPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(0.41, $rate, 0.0001);

        $urls = array_map(static fn ($r): string => (string) $r->getUri(), $client->sentRequests());
        self::assertStringContainsString('symbol=XLMUSDT', $urls[0], 'Binance quotes USD against USDT');
        self::assertStringContainsString('ids=stellar&vs_currencies=usd', $urls[1], 'Coingecko id for XLM');
        self::assertStringContainsString('pair=XLMUSD', $urls[2]);
    }

    public function testFiltersADivergentOracleLikeXrp(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.40"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"stellar":{"usd":0.40}}'));
        // 0.45 sits more than 5 % off the average of the three; the two agreeing feeds remain.
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXLMZUSD":{"c":["0.45","100"]}}}'));

        $rate = (new XlmPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(0.40, $rate, 0.0001);
    }

    public function testFailsWhenNoOracleAnswers(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(500));
        $client->queueResponse('api.coingecko.com', new Response(500));
        $client->queueResponse('api.kraken.com', new Response(500));

        self::assertFalse((new XlmPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD'));
    }
}
