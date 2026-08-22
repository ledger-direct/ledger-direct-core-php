<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\XrpPriceProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class XrpPriceProviderTest extends TestCase
{
    public function testAveragesAgreeingOracles(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.55"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":0.56}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["0.54","100"]}}}'));

        $provider = new XrpPriceProvider($client, new HttpFactory(), new RecordingLogger());

        $rate = $provider->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(0.55, $rate, 0.0001);
    }

    public function testRoundsToFiveDecimalPlacesWhenRequested(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.551111"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":0.552222}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["0.553333","100"]}}}'));

        $provider = new XrpPriceProvider($client, new HttpFactory(), new RecordingLogger());

        $rate = $provider->getCurrentExchangeRate('USD', round: true);

        self::assertSame(round((0.551111 + 0.552222 + 0.553333) / 3, 5), $rate);
    }

    public function testExcludesAnOracleBeyondTheDivergenceBand(): void
    {
        // Binance/Coingecko agree at 1.00, Kraken is 10% off (band is 5%).
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"1.00"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":1.00}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["1.10","100"]}}}'));

        $provider = new XrpPriceProvider($client, new HttpFactory(), new RecordingLogger());

        $rate = $provider->getCurrentExchangeRate('USD');

        // If Kraken's 1.10 had survived the filter the average would be ~1.033.
        self::assertEqualsWithDelta(1.00, $rate, 0.0001);
    }

    public function testReturnsFalseAndLogsAWarningPerFailedOracleWhenAllFail(): void
    {
        $client = new FakeHttpClient(); // nothing queued: every oracle call throws
        $logger = new RecordingLogger();

        $provider = new XrpPriceProvider($client, new HttpFactory(), $logger);

        $rate = $provider->getCurrentExchangeRate('USD');

        self::assertFalse($rate);
        self::assertSame(3, $logger->count());
        foreach ($logger->records() as $record) {
            self::assertSame('warning', $record['level']);
        }
    }
}
