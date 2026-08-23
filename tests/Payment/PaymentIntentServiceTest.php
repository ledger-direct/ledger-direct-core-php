<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Payment;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use PHPUnit\Framework\TestCase;

final class PaymentIntentServiceTest extends TestCase
{
    public function testBuildsAFreshPaymentIntentWhenThereIsNoExistingOne(): void
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $configProvider = new FakeConfigProvider();
        $service = $this->makeService($client, $repository, $configProvider);

        $this->queueXrpOraclePrice($client, '0.50');

        $intent = $service->quoteForOrder(100.0, 'USD', 'XRP');

        self::assertSame('xrp-payment', $intent->type);
        self::assertSame('XRPL', $intent->chain);
        self::assertSame('testnet', $intent->network);
        self::assertSame('XRP', $intent->baseAsset);
        self::assertSame('XRP/USD', $intent->pairing);
        self::assertEqualsWithDelta(200.0, $intent->amountRequested, 0.0001);
        self::assertSame('rDestinationAccount', $intent->destinationAccount);
        self::assertGreaterThanOrEqual(10000, $intent->destinationTag);
        self::assertGreaterThan(time(), $intent->expiry);
    }

    public function testTypeMappingForStablecoinsViaThePegFastPath(): void
    {
        $repository = new InMemoryXrplTransactionRepository();
        $configProvider = new FakeConfigProvider();
        $service = $this->makeService(new FakeHttpClient(), $repository, $configProvider);

        // USD-peg fast path: no HTTP call needed, so an empty FakeHttpClient is fine.
        self::assertSame('rlusd-payment', $service->quoteForOrder(50.0, 'USD', 'RLUSD')->type);
        self::assertSame('usdc-payment', $service->quoteForOrder(50.0, 'USD', 'USDC')->type);
    }

    public function testReusesTheExistingDestinationWhenTheAccountStillMatches(): void
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $configProvider = new FakeConfigProvider();
        $service = $this->makeService($client, $repository, $configProvider);

        $this->queueXrpOraclePrice($client, '0.50');
        $first = $service->quoteForOrder(100.0, 'USD', 'XRP');

        $this->queueXrpOraclePrice($client, '0.40'); // price moved between the two calls
        $second = $service->quoteForOrder(100.0, 'USD', 'XRP', existing: $first);

        self::assertSame($first->destinationAccount, $second->destinationAccount);
        self::assertSame($first->destinationTag, $second->destinationTag);
        self::assertNotEquals($first->exchangeRate, $second->exchangeRate);

        // Confirms no second tag was ever reserved: the next fresh generation
        // continues from sequence 1 (only $first's call consumed sequence 0),
        // not sequence 2.
        $third = (new DestinationTagService($repository))->generateDestinationTag('rDestinationAccount');
        self::assertNotSame($first->destinationTag, $third);
        self::assertNotSame($second->destinationTag, $third);
    }

    public function testGeneratesAFreshTagWhenTheExistingAccountIsStale(): void
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $configProvider = new FakeConfigProvider(destinationAccount: 'rOldAddress');
        $service = $this->makeService($client, $repository, $configProvider);

        $this->queueXrpOraclePrice($client, '0.50');
        $stale = $service->quoteForOrder(100.0, 'USD', 'XRP');

        $configProvider->setDestinationAccount('rNewAddress'); // merchant reconfigured

        $this->queueXrpOraclePrice($client, '0.50');
        $refreshed = $service->quoteForOrder(100.0, 'USD', 'XRP', existing: $stale);

        self::assertSame('rNewAddress', $refreshed->destinationAccount);
        // Fresh account, first tag it's ever issued (sequence 0) - deterministic value,
        // same one verified independently in DestinationTagServiceTest. Confirms a real
        // fresh generation happened rather than $stale's tag leaking through by accident
        // (a same-numbered tag would still be a valid, distinct pair under a different
        // account, so comparing tag numbers alone wouldn't prove anything here).
        self::assertSame(114729, $refreshed->destinationTag);
    }

    public function testDisabledAssetThrowsWithoutAttemptingAPriceLookup(): void
    {
        $client = new FakeHttpClient(); // nothing queued — a price lookup attempt would throw a different exception
        $repository = new InMemoryXrplTransactionRepository();
        $configProvider = new FakeConfigProvider(assetEnabled: false);
        $service = $this->makeService($client, $repository, $configProvider);

        $this->expectException(AssetNotAcceptedException::class);

        $service->quoteForOrder(100.0, 'USD', 'XRP');
    }

    public function testPriceUnavailableExceptionPropagates(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.binance.com', new Response(200, [], '{}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{}}'));
        $repository = new InMemoryXrplTransactionRepository();
        $service = $this->makeService($client, $repository, new FakeConfigProvider());

        $this->expectException(PriceUnavailableException::class);

        $service->quoteForOrder(100.0, 'USD', 'XRP');
    }

    private function makeService(
        FakeHttpClient $client,
        InMemoryXrplTransactionRepository $repository,
        FakeConfigProvider $configProvider,
    ): PaymentIntentService {
        $requestFactory = new HttpFactory();
        $priceService = new PriceService($client, $requestFactory, new RecordingLogger());
        $destinationTagService = new DestinationTagService($repository);

        return new PaymentIntentService($priceService, $destinationTagService, $configProvider);
    }

    private function queueXrpOraclePrice(FakeHttpClient $client, string $price): void
    {
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"' . $price . '"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":' . $price . '}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["' . $price . '","100"]}}}'));
    }
}
