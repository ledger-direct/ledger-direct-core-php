<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentIntentServiceTest extends TestCase
{
    public function testBuildsAStellarXlmIntentWithTheContractsNames(): void
    {
        $client = new FakeHttpClient();
        $this->queueXlmOraclePrice($client, '0.40');
        $repository = new InMemoryStellarPaymentRepository();
        $repository->scriptSequences(StellarFixtures::MERCHANT, [0]);
        $clock = new FrozenClock(1_700_000_000);
        $service = $this->makeService($client, $repository, new FakeConfigProvider(destinationAccount: StellarFixtures::MERCHANT, quoteExpirySeconds: 300), $clock);

        $intent = $service->quoteForOrder(10.0, 'USD', 'XLM');

        self::assertSame('stellar-xlm-payment', $intent->type);
        self::assertSame('STELLAR', $intent->chain);
        self::assertSame('testnet', $intent->network);
        self::assertSame('XLM', $intent->baseAsset);
        self::assertSame('XLM/USD', $intent->pairing);
        self::assertEqualsWithDelta(0.40, $intent->exchangeRate, 0.0001);
        self::assertSame(25.0, $intent->amountRequested, 'a float, XLM is the native asset');
        self::assertSame(StellarFixtures::MERCHANT, $intent->destinationAccount);
        self::assertSame(114729, $intent->destinationTag, 'the memo id from sequence 0');
        self::assertSame(1_700_000_300, $intent->expiry);
        self::assertNull($intent->hash);

        $round = $intent->toArray();
        self::assertSame(1, $round['schema_version']);
        self::assertSame('STELLAR', $round['chain']);
    }

    public function testRoundsToFivePlacesLikeXrp(): void
    {
        $client = new FakeHttpClient();
        $this->queueXlmOraclePrice($client, '0.3');
        $service = $this->makeService($client, new InMemoryStellarPaymentRepository(), new FakeConfigProvider());

        self::assertSame(33.33333, $service->quoteForOrder(10.0, 'USD', 'XLM')->amountRequested);
    }

    public function testARefreshKeepsTheMemoIdWhileTheReceivingAccountIsUnchanged(): void
    {
        $client = new FakeHttpClient();
        $this->queueXlmOraclePrice($client, '0.40');
        $this->queueXlmOraclePrice($client, '0.50');
        $repository = new InMemoryStellarPaymentRepository();
        $config = new FakeConfigProvider(destinationAccount: StellarFixtures::MERCHANT);
        $service = $this->makeService($client, $repository, $config);

        $first = $service->quoteForOrder(10.0, 'USD', 'XLM');
        $refreshed = $service->quoteForOrder(10.0, 'USD', 'XLM', $first);

        self::assertSame($first->destinationTag, $refreshed->destinationTag);
        self::assertSame(20.0, $refreshed->amountRequested, 'the price moved, the memo did not');

        $this->queueXlmOraclePrice($client, '0.50');
        $config->setDestinationAccount('GNEWACCOUNT');
        $moved = $service->quoteForOrder(10.0, 'USD', 'XLM', $refreshed);

        self::assertSame('GNEWACCOUNT', $moved->destinationAccount);
        self::assertNotSame($refreshed->destinationTag, $moved->destinationTag, 'a new account gets a fresh memo id');
    }

    public function testADisabledAssetIsNotAccepted(): void
    {
        $service = $this->makeService(new FakeHttpClient(), new InMemoryStellarPaymentRepository(), new FakeConfigProvider(assetEnabled: false));

        $this->expectException(AssetNotAcceptedException::class);
        $service->quoteForOrder(10.0, 'USD', 'XLM');
    }

    public function testIssuedAssetsAreNotPartOfThisSliceYet(): void
    {
        $service = $this->makeService(new FakeHttpClient(), new InMemoryStellarPaymentRepository(), new FakeConfigProvider());

        $this->expectException(InvalidArgumentException::class);
        $service->quoteForOrder(10.0, 'USD', 'USDC');
    }

    private function makeService(
        FakeHttpClient $client,
        InMemoryStellarPaymentRepository $repository,
        FakeConfigProvider $config,
        ?FrozenClock $clock = null,
    ): PaymentIntentService {
        return new PaymentIntentService(
            new PriceService($client, new HttpFactory(), new RecordingLogger()),
            new MemoIdService($repository),
            $config,
            $clock,
        );
    }

    private function queueXlmOraclePrice(FakeHttpClient $client, string $price): void
    {
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"' . $price . '"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"stellar":{"usd":' . $price . '}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXLMZUSD":{"c":["' . $price . '","100"]}}}'));
    }
}
