<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryCache;
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

    /**
     * 0.987652 / 8 = 0.1234565 exactly — the only quote in this suite whose
     * discarded digit is a bare 5, so the only one that can tell HALF_UP
     * apart from DOWN. Not academic: PriceService resolves the rounding mode
     * by case name to span brick/math's 0.15 rename, and a resolver that
     * picked the wrong case would leave every other assertion here green.
     */
    public function testXrpQuoteRoundsHalfUpRatherThanDown(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"8"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":8}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["8","100"]}}}'));

        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $quote = $service->getCryptoPriceForOrder(0.987652, 'USD', 'XRP', 'testnet');

        self::assertEqualsWithDelta(0.12346, $quote->amountRequested, 0.000001);
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

    // --- Rate caching (optional; see INVARIANTS.md § Rate caching) ---------

    private const RLUSD_EUR_KEY = 'ledger-direct.rate.v1.mainnet.RLUSD.EUR';

    public function testAFreshCacheHitCostsNoHttpRequestAtAll(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        // Nothing else is queued: a second HTTP call would throw, not silently pass.
        $quote = $service->getCryptoPriceForOrder(45.95, 'EUR', 'RLUSD', 'mainnet');

        self::assertCount(1, $client->sentRequests());
        self::assertEqualsWithDelta(0.919, $quote->exchangeRate, 0.0001);
        // The amount still tracks the order total — only the rate is cached.
        self::assertSame('50.00', $quote->amountRequested['value']);
    }

    public function testTheEntryIsWrittenWithTheStaleHorizonAsItsTtl(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        // 60s fresh x 5 — PSR-16 cannot return an expired value, so the entry
        // must outlive its own freshness for stale-while-error to be possible.
        self::assertSame(300, $cache->ttlFor(self::RLUSD_EUR_KEY));
    }

    public function testAnExpiredEntryIsRefetchedAndWrittenBack(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.8}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $cache->ageEntry(self::RLUSD_EUR_KEY, 61);

        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertEqualsWithDelta(0.8, $quote->exchangeRate, 0.0001);
        self::assertCount(2, $client->sentRequests());

        // Written back, so the next call is a fresh hit again.
        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        self::assertEqualsWithDelta(0.8, $quote->exchangeRate, 0.0001);
        self::assertCount(2, $client->sentRequests());
    }

    /**
     * The actual point of caching here: an oracle hiccup must not make the
     * payment method vanish mid-checkout.
     */
    public function testAStaleRateIsServedWhenNoOracleIsReachableWithinTheHorizon(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $logger = new RecordingLogger();
        $service = new PriceService($client, new HttpFactory(), $logger, $cache);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $cache->ageEntry(self::RLUSD_EUR_KEY, 120); // stale, but inside the 300s horizon

        // Nothing queued any more: the refetch fails, the stale rate carries it.
        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertEqualsWithDelta(0.919, $quote->exchangeRate, 0.0001);

        // The provider logs its own oracle failure first; the stale-serve
        // warning is the one this service adds on top.
        $stale = $logger->records()[$logger->count() - 1];
        self::assertSame('warning', $stale['level']);
        self::assertStringContainsString('stale exchange rate', $stale['message']);
        self::assertSame(120, $stale['context']['age_seconds']);
        self::assertSame('RLUSD/EUR', $stale['context']['pairing']);
    }

    public function testAnEntryBeyondTheStaleHorizonIsNotServed(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $cache->ageEntry(self::RLUSD_EUR_KEY, 301);

        $this->expectException(PriceUnavailableException::class);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
    }

    public function testFailuresAreNeverCached(): void
    {
        $client = new FakeHttpClient();
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        try {
            $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        } catch (PriceUnavailableException) {
            // expected
        }

        self::assertSame([], $cache->keys());
    }

    public function testMainnetAndTestnetRatesDoNotCollide(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.5}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $mainnet = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $testnet = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'testnet');

        self::assertEqualsWithDelta(0.919, $mainnet->exchangeRate, 0.0001);
        self::assertEqualsWithDelta(0.5, $testnet->exchangeRate, 0.0001);
        self::assertSame(
            ['ledger-direct.rate.v1.mainnet.RLUSD.EUR', 'ledger-direct.rate.v1.testnet.RLUSD.EUR'],
            $cache->keys(),
        );
    }

    /**
     * An unreachable cache backend degrades to "no cache", never to "no
     * payment".
     */
    public function testABrokenCacheStillYieldsAPriceAndLogsInstead(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $cache->failOn('get', 'set');
        $logger = new RecordingLogger();
        $service = new PriceService($client, new HttpFactory(), $logger, $cache);

        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertEqualsWithDelta(0.919, $quote->exchangeRate, 0.0001);
        self::assertSame(2, $logger->count()); // one read failure, one write failure
        self::assertStringContainsString('rate cache read failed', $logger->records()[0]['message']);
        self::assertStringContainsString('rate cache write failed', $logger->records()[1]['message']);
    }

    /**
     * A foreign or outdated value under the same key reads as a miss rather
     * than raising a TypeError.
     */
    public function testAnUnusableCacheEntryIsTreatedAsAMiss(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $cache->set(self::RLUSD_EUR_KEY, 'not-an-entry');
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertEqualsWithDelta(0.919, $quote->exchangeRate, 0.0001);
    }

    /**
     * A backend that round-trips the entry through JSON hands a whole-number
     * rate back as int — which must still count as a hit, not a permanent
     * miss for every pairing that happens to sit on a round number.
     */
    public function testAnIntegerRateFromAJsonBackedCacheStillHits(): void
    {
        $client = new FakeHttpClient(); // nothing queued: a refetch would throw
        $cache = new InMemoryCache();
        $cache->set(self::RLUSD_EUR_KEY, json_decode(
            json_encode(['rate' => 2.0, 'fetched_at' => time()]),
            true,
        ));
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache);

        $quote = $service->getCryptoPriceForOrder(100.0, 'EUR', 'RLUSD', 'mainnet');

        self::assertSame(2.0, $quote->exchangeRate);
        self::assertSame('50.00', $quote->amountRequested['value']);
    }

    public function testWithoutACacheEveryQuoteStillHitsTheOracles(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger());

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertCount(2, $client->sentRequests());
    }

    public function testTheFreshTtlIsConfigurableThroughTheConstructor(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $cache = new InMemoryCache();
        $service = new PriceService($client, new HttpFactory(), new RecordingLogger(), $cache, 600);

        $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $cache->ageEntry(self::RLUSD_EUR_KEY, 300); // stale at the default TTL, fresh at 600

        $quote = $service->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertCount(1, $client->sentRequests());
        self::assertEqualsWithDelta(0.919, $quote->exchangeRate, 0.0001);
        self::assertSame(3000, $cache->ttlFor(self::RLUSD_EUR_KEY));
    }
}
