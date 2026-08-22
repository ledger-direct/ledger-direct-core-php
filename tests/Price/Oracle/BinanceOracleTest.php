<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price\Oracle;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class BinanceOracleTest extends TestCase
{
    public function testReturnsParsedPrice(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"symbol":"XRPUSDT","price":"0.55000000"}'));

        $oracle = new BinanceOracle($client, new HttpFactory());

        self::assertSame(0.55, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testUsesUsdtSymbolForUsdQuote(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('symbol=XRPUSDT', new Response(200, [], '{"price":"0.55"}'));

        $oracle = new BinanceOracle($client, new HttpFactory());

        self::assertSame(0.55, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testReturnsZeroWhenPriceKeyIsMissing(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"code":-1121,"msg":"Invalid symbol."}'));

        $oracle = new BinanceOracle($client, new HttpFactory());

        self::assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }
}
