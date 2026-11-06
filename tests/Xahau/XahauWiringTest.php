<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xahau;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Xahau reuses the XRPL classes with the chain as a parameter. Each test here
 * pins one place the parameter reaches; the XRPL tests, unchanged, pin that
 * the default still means the XRP Ledger.
 */
final class XahauWiringTest extends TestCase
{
    private const MERCHANT = 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2';

    public function testTheClientAsksXahauNodesPerNetwork(): void
    {
        $client = new FakeHttpClient();
        $ok = '{"result":{"status":"success","transactions":[],"marker":null}}';
        $client->queueResponse('xahau-test.net', new Response(200, [], $ok));
        $client->queueResponse('xahau.network', new Response(200, [], $ok));

        $xahau = new XrplClient($client, new HttpFactory(), new HttpFactory(), 'XAHAU');
        $xahau->fetchAccountTransactions(self::MERCHANT, 'testnet');
        $xahau->fetchAccountTransactions(self::MERCHANT, 'mainnet');

        $urls = array_map(static fn ($r): string => (string) $r->getUri(), $client->sentRequests());
        self::assertSame(['https://xahau-test.net/', 'https://xahau.network/'], $urls);
    }

    public function testAnUnknownChainIsRefusedByTheClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new XrplClient(new FakeHttpClient(), new HttpFactory(), new HttpFactory(), 'STELLAR');
    }

    /**
     * Not retried inside the request: the sync runs in a shop request and must
     * not sleep. The code lets a caller tell it apart; the message keeps Retry-After.
     */
    public function testARateLimitedNodeIsReportedWithItsOwnCode(): void
    {
        foreach ([429, 503] as $status) {
            $client = new FakeHttpClient();
            $client->queueResponse('xahau.network', new Response($status, ['Retry-After' => '6'], ''));

            try {
                (new XrplClient($client, new HttpFactory(), new HttpFactory(), 'XAHAU'))->tx('ABC', 'mainnet');
                self::fail("HTTP {$status} must throw");
            } catch (XrplRpcException $exception) {
                self::assertSame(XrplClient::ERROR_RATE_LIMITED, $exception->error);
                self::assertStringContainsString('retry after 6', $exception->getMessage());
            }
        }
    }

    public function testTheIntentServiceQuotesXahOnXahau(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"xahau":{"usd":0.0100}}'));
        $client->queueResponse('openapi.bitrue.com', new Response(200, [], '{"symbol":"XAHUSDT","price":"0.0102"}'));
        $config = new RecordingConfig();
        $clock = new FrozenClock();

        $service = new PaymentIntentService(
            new PriceService($client, new HttpFactory(), new RecordingLogger()),
            new DestinationTagService(new InMemoryXrplTransactionRepository()),
            $config,
            $clock,
            'XAHAU',
        );

        $intent = $service->quoteForOrder(10.10, 'USD', 'XAH');

        self::assertSame('xahau-xah-payment', $intent->type);
        self::assertSame('XAHAU', $intent->chain);
        self::assertSame('testnet', $intent->network);
        self::assertSame('XAH', $intent->baseAsset);
        self::assertSame('XAH/USD', $intent->pairing);
        self::assertEqualsWithDelta(0.0101, $intent->exchangeRate, 1e-9);
        self::assertSame(1000.0, $intent->amountRequested, 'native: the number itself, five places');
        self::assertSame(self::MERCHANT, $intent->destinationAccount);
        self::assertSame(['XAHAU'], array_values(array_unique($config->chainsAsked)), 'configuration is read under the chain');
    }

    public function testAnXrplAssetIsNotQuotedOnXahau(): void
    {
        $service = new PaymentIntentService(
            new PriceService(new FakeHttpClient(), new HttpFactory(), new RecordingLogger()),
            new DestinationTagService(new InMemoryXrplTransactionRepository()),
            new RecordingConfig(),
            null,
            'XAHAU',
        );

        $this->expectException(InvalidArgumentException::class);
        $service->quoteForOrder(10.0, 'USD', 'RLUSD');
    }

    public function testTheRootWiresTheChainThrough(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('xahau-test.net', new Response(200, [], '{"result":{"status":"success","transactions":[],"marker":null}}'));

        $core = LedgerDirect::create(
            $client,
            new HttpFactory(),
            new HttpFactory(),
            new RecordingLogger(),
            new InMemoryXrplTransactionRepository(),
            new RecordingConfig(),
            new InMemoryCache(),
            chain: 'XAHAU',
        );

        self::assertSame('XAHAU', $core->chain());
        $core->syncService()->syncTransactions(self::MERCHANT, 'testnet');
        self::assertStringContainsString('xahau-test.net', (string) $client->lastRequest()?->getUri());
    }

    public function testTheRootRefusesAChainWithItsOwnRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LedgerDirect::create(
            new FakeHttpClient(),
            new HttpFactory(),
            new HttpFactory(),
            new RecordingLogger(),
            new InMemoryXrplTransactionRepository(),
            new RecordingConfig(),
            chain: 'STELLAR',
        );
    }

    /**
     * The same r-address can receive on both chains. One chain's sync must not
     * hold back the other's; the XRPL key stays as it was.
     */
    public function testTheThrottleKeepsTheChainsApart(): void
    {
        $cache = new InMemoryCache(new FrozenClock());
        $xrpl = new SyncThrottle($cache, new RecordingLogger());
        $xahau = new SyncThrottle($cache, new RecordingLogger(), chain: 'XAHAU');

        $xrpl->markSynced('testnet', self::MERCHANT);

        self::assertFalse($xrpl->shouldSync('testnet', self::MERCHANT));
        self::assertTrue($xahau->shouldSync('testnet', self::MERCHANT));
        self::assertTrue($cache->has('ledger-direct.sync.v1.testnet.' . self::MERCHANT), 'XRPL key unchanged');
    }

    public function testTheRequestNamesTheXahauNetworkForXaman(): void
    {
        self::assertSame(
            'https://xrplf.org//send?to=' . self::MERCHANT . '&dt=1050508800&amount=1.5&network=XAHAUTESTNET',
            PaymentUri::forIntent($this->xahIntent('testnet'), '1.5'),
        );
        self::assertStringEndsWith('&network=XAHAU', PaymentUri::forIntent($this->xahIntent('mainnet'), '1.5'));
        self::assertStringEndsWith('&dt=1050508800&network=XAHAU', PaymentUri::forIntent($this->xahIntent('mainnet'), '1.5', PaymentUri::AMOUNT_NONE));
    }

    public function testXahauHasItsOwnTablesWithTheSameColumns(): void
    {
        $xrpl = Schema::tables();
        $xahau = Schema::tables('XAHAU');

        self::assertSame([Schema::TABLE_XAHAU_TX, Schema::TABLE_XAHAU_DESTINATION_TAG], array_keys($xahau));
        self::assertSame($xrpl[Schema::TABLE_TX]['columns'], $xahau[Schema::TABLE_XAHAU_TX]['columns']);
        self::assertSame($xrpl[Schema::TABLE_DESTINATION_TAG], $xahau[Schema::TABLE_XAHAU_DESTINATION_TAG]);
        self::assertSame(array_values($xrpl[Schema::TABLE_TX]['indexes']), array_values($xahau[Schema::TABLE_XAHAU_TX]['indexes']));

        // PostgreSQL wants index names unique per schema: none may repeat an XRPL one.
        $names = static fn (array $t): array => [...array_keys($t['unique']), ...array_keys($t['indexes'])];
        self::assertSame([], array_intersect($names($xrpl[Schema::TABLE_TX]), $names($xahau[Schema::TABLE_XAHAU_TX])));

        $ddl = implode("\n", Schema::mysql('ps_', chain: 'XAHAU'));
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `ps_ledger_direct_xahau_tx`', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `ps_ledger_direct_xahau_destination_tag`', $ddl);
        self::assertStringNotContainsString('ledger_direct_xrpl', $ddl);
    }

    private function xahIntent(string $network): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xahau-xah-payment',
            chain: 'XAHAU',
            network: $network,
            baseAsset: 'XAH',
            quoteCurrency: 'USD',
            pairing: 'XAH/USD',
            exchangeRate: 0.0101,
            amountRequested: 1.5,
            destinationAccount: self::MERCHANT,
            destinationTag: 1050508800,
        );
    }
}

/** Records under which chain the configuration was read. */
final class RecordingConfig implements ConfigProviderInterface
{
    /** @var list<string> */
    public array $chainsAsked = [];

    public function getNetwork(string $chain): string
    {
        $this->chainsAsked[] = $chain;

        return 'testnet';
    }

    public function getDestinationAccount(string $chain): string
    {
        $this->chainsAsked[] = $chain;

        return 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2';
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $this->chainsAsked[] = $chain;

        return true;
    }

    public function getQuoteExpirySeconds(): int
    {
        return 300;
    }
}
