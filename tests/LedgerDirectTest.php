<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use PHPUnit\Framework\TestCase;

final class LedgerDirectTest extends TestCase
{
    public function testEveryGetterHandsOutTheSameInstance(): void
    {
        $core = $this->create(cache: new InMemoryCache());

        self::assertSame($core->priceService(), $core->priceService());
        self::assertSame($core->xrplClient(), $core->xrplClient());
        self::assertSame($core->syncService(), $core->syncService());
        self::assertSame($core->destinationTagService(), $core->destinationTagService());
        self::assertSame($core->paymentIntentService(), $core->paymentIntentService());
        self::assertSame($core->settlementPolicy(), $core->settlementPolicy());
        self::assertSame($core->syncThrottle(), $core->syncThrottle());
    }

    /**
     * A per-process throttle would be useless — the status endpoint's call
     * *is* the process — so without a shared cache there is none.
     */
    public function testTheThrottleExistsOnlyWithACache(): void
    {
        self::assertNull($this->create()->syncThrottle());
        self::assertInstanceOf(SyncThrottle::class, $this->create(cache: new InMemoryCache())->syncThrottle());
    }

    public function testPaymentStatusIsJudgedByTheInjectedClock(): void
    {
        $clock = new FrozenClock(1_700_000_000);
        $core = $this->create(clock: $clock);
        $intent = $this->xrpQuote(100.0, expiry: 1_700_000_480);

        self::assertSame(480, $core->paymentStatus($intent)->toArray()['seconds_left']);

        $clock->advance(480);

        self::assertSame(PaymentStatus::EXPIRED, $core->paymentStatus($intent)->state());
    }

    public function testTheQuoteExpiryComesFromTheInjectedClock(): void
    {
        $client = new FakeHttpClient();
        $clock = new FrozenClock(1_700_000_000);
        $core = $this->create(client: $client, clock: $clock, config: new FakeConfigProvider(quoteExpirySeconds: 300));

        // USD-peg fast path: no oracle call, so nothing needs queueing.
        $intent = $core->paymentIntentService()->quoteForOrder(50.0, 'USD', 'RLUSD');

        self::assertSame(1_700_000_300, $intent->expiry);
    }

    public function testToleranceAndFreshTtlReachTheirServices(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple-usd":{"eur":0.919}}'));
        $clock = new FrozenClock();
        $core = $this->create(
            client: $client,
            cache: new InMemoryCache($clock),
            clock: $clock,
            nativeAssetTolerance: '0',
            rateFreshTtlSeconds: 10,
        );

        self::assertFalse($core->settlementPolicy()->isSettled($this->xrpQuote(100.0)->withFulfillment('H', 99.999)));

        $core->priceService()->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');
        $clock->advance(11);
        $core->priceService()->getCryptoPriceForOrder(91.9, 'EUR', 'RLUSD', 'mainnet');

        self::assertCount(2, $client->sentRequests(), 'a 10 s TTL crossed by 11 s refetches');
    }

    public function testThePortsAreReachableForAnAdapterThatNeedsThem(): void
    {
        $logger = new RecordingLogger();
        $repository = new InMemoryXrplTransactionRepository();
        $config = new FakeConfigProvider();
        $core = $this->create(logger: $logger, repository: $repository, config: $config);

        self::assertSame($logger, $core->logger());
        self::assertSame($repository, $core->transactionRepository());
        self::assertSame($config, $core->configProvider());
    }

    private function create(
        ?FakeHttpClient $client = null,
        ?RecordingLogger $logger = null,
        ?InMemoryXrplTransactionRepository $repository = null,
        ?FakeConfigProvider $config = null,
        ?InMemoryCache $cache = null,
        ?FrozenClock $clock = null,
        string $nativeAssetTolerance = '0.0015',
        int $rateFreshTtlSeconds = 60,
    ): LedgerDirect {
        return LedgerDirect::create(
            $client ?? new FakeHttpClient(),
            new HttpFactory(),
            new HttpFactory(),
            $logger ?? new RecordingLogger(),
            $repository ?? new InMemoryXrplTransactionRepository(),
            $config ?? new FakeConfigProvider(),
            $cache,
            $clock,
            $nativeAssetTolerance,
            $rateFreshTtlSeconds,
        );
    }

    private function xrpQuote(float $requested, ?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.25,
            amountRequested: $requested,
            destinationAccount: 'rMerchant',
            destinationTag: 1,
            expiry: $expiry,
        );
    }
}
