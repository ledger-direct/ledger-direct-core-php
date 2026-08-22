<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PriceServiceTest extends TestCase
{
    public function testXrpQuoteIsRoundedToFiveDecimalPlaces(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"3"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":3}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["3","100"]}}}'));

        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $quote = $service->getCryptoPriceForOrder(100.0, 'USD', 'XRP', 'testnet');

        self::assertSame('XRP', $quote->baseAsset);
        self::assertSame('USD', $quote->quoteCurrency);
        self::assertSame('XRP/USD', $quote->pairing);
        self::assertEqualsWithDelta(3.0, $quote->exchangeRate, 0.0001);
        // 100 / 3 = 33.33333... rounded HALF_UP to 5 places.
        self::assertEqualsWithDelta(33.33333, $quote->amountRequested, 0.000001);
    }

    public function testRlusdAmountKeepsTrailingZerosAtTwoDecimalPlaces(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));

        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        // 91.9 / 0.919 = 100 exactly — proves the BigDecimal fix keeps "100.00", not "100".
        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertIsArray($quote->amountRequested);
        self::assertSame('100.00', $quote->amountRequested['value']);
        self::assertSame('rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', $quote->amountRequested['issuer']);
        self::assertSame('524C555344000000000000000000000000000000', $quote->amountRequested['currency']);
    }

    public function testUsdcAmountUsesTestnetIssuerForTestnetNetwork(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"usd-coin":{"eur":0.919}}'));

        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'USDC', 'testnet');

        self::assertIsArray($quote->amountRequested);
        self::assertSame('100.00', $quote->amountRequested['value']);
        self::assertSame('rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', $quote->amountRequested['issuer']);
    }

    public function testStablecoinUsdPegFastPathReachesThroughPriceService(): void
    {
        $client = new FakeHttpClient(); // nothing queued — peg path must not touch HTTP
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $quote = $service->getCryptoPriceForOrder(50.0, 'USD', 'RLUSD', 'testnet');

        self::assertSame(1.0, $quote->exchangeRate);
        self::assertSame('50.00', $quote->amountRequested['value']);
    }

    public function testUnsupportedBaseAssetThrows(): void
    {
        $service = new PriceService(new FakeHttpClient(), new HttpFactory(), new RecordingLogger());

        $this->expectException(InvalidArgumentException::class);

        $service->getCryptoPriceForOrder(100.0, 'USD', 'BTC', 'mainnet');
    }

    public function testAllOraclesFailingThrowsPriceUnavailableInsteadOfDividing(): void
    {
        $client = new FakeHttpClient(); // nothing queued: every oracle call throws
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $this->expectException(PriceUnavailableException::class);

        $service->getCryptoPriceForOrder(100.0, 'USD', 'XRP', 'mainnet');
    }
}
