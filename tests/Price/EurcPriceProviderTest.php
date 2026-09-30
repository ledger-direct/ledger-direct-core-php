<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\EurcPriceProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class EurcPriceProviderTest extends TestCase
{
    public function testTheEurPegSkipsTheOracles(): void
    {
        $client = new FakeHttpClient(); // nothing queued: a call would throw

        self::assertSame(1.0, (new EurcPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('EUR'));
        self::assertCount(0, $client->sentRequests());
    }

    public function testAnyOtherQuoteAsksCoingeckoForEuroCoin(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"euro-coin":{"usd":1.08}}'));

        $rate = (new EurcPriceProvider($client, new HttpFactory(), new RecordingLogger()))->getCurrentExchangeRate('USD');

        self::assertEqualsWithDelta(1.08, $rate, 0.0001);
        self::assertStringContainsString('ids=euro-coin&vs_currencies=usd', (string) $client->lastRequest()?->getUri());
        self::assertCount(1, $client->sentRequests(), 'Coingecko only');
    }
}
