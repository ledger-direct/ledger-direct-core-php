<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price\Oracle;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\Oracle\CoingeckoOracle;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CoingeckoOracleTest extends TestCase
{
    public function testMapsKnownCurrencyCodesAndReturnsParsedPrice(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(
            'ids=ripple&vs_currencies=usd-coin',
            new Response(200, [], '{"ripple":{"usd-coin":1.82}}'),
        );

        $oracle = new CoingeckoOracle($client, new HttpFactory());

        self::assertSame(1.82, $oracle->getCurrentPriceForPair('XRP', 'USDC'));
    }

    public function testFallsBackToLowercaseForUnknownCurrencyCode(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(
            'ids=ripple&vs_currencies=eur',
            new Response(200, [], '{"ripple":{"eur":0.51}}'),
        );

        $oracle = new CoingeckoOracle($client, new HttpFactory());

        self::assertSame(0.51, $oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }

    public function testReturnsZeroWhenPairIsMissingFromResponse(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{}'));

        $oracle = new CoingeckoOracle($client, new HttpFactory());

        self::assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }
}
