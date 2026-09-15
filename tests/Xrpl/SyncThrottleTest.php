<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use PHPUnit\Framework\TestCase;

final class SyncThrottleTest extends TestCase
{
    public function testTheSecondCallInsideTheIntervalDoesNotSync(): void
    {
        $clock = new FrozenClock();
        $throttle = new SyncThrottle(new InMemoryCache($clock), new RecordingLogger(), 5, $clock);

        self::assertTrue($throttle->shouldSync('testnet', 'rAccount'));
        $throttle->markSynced('testnet', 'rAccount');

        self::assertFalse($throttle->shouldSync('testnet', 'rAccount'));
        $clock->advance(4);
        self::assertFalse($throttle->shouldSync('testnet', 'rAccount'));
        $clock->advance(1);
        self::assertTrue($throttle->shouldSync('testnet', 'rAccount'), 'due again once the interval has passed');
    }

    public function testMainnetAndTestnetAreThrottledSeparately(): void
    {
        $throttle = new SyncThrottle(new InMemoryCache(new FrozenClock()), new RecordingLogger());

        $throttle->markSynced('testnet', 'rAccount');

        self::assertFalse($throttle->shouldSync('testnet', 'rAccount'));
        self::assertTrue($throttle->shouldSync('mainnet', 'rAccount'));
        self::assertTrue($throttle->shouldSync('testnet', 'rOtherAccount'));
    }

    /**
     * A broken cache degrades to "always sync", never to "never sync".
     */
    public function testABrokenCacheMeansSyncAndAWarning(): void
    {
        $cache = new InMemoryCache();
        $cache->failOn('get', 'set');
        $logger = new RecordingLogger();
        $throttle = new SyncThrottle($cache, $logger);

        $throttle->markSynced('testnet', 'rAccount');

        self::assertTrue($throttle->shouldSync('testnet', 'rAccount'));
        self::assertSame(['warning', 'warning'], array_column($logger->records(), 'level'));
    }

    public function testSyncIfDueRunsTheSyncExactlyOncePerInterval(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', $this->emptyAccountTx());
        $client->queueResponse('s.altnet.rippletest.net', $this->emptyAccountTx());
        $sync = new SyncService(
            new XrplClient($client, new HttpFactory(), new HttpFactory()),
            new InMemoryXrplTransactionRepository(),
            new RecordingLogger(),
        );
        $clock = new FrozenClock();
        $throttle = new SyncThrottle(new InMemoryCache($clock), new RecordingLogger(), 5, $clock);

        self::assertTrue($throttle->syncIfDue($sync, 'rAccount', 'testnet'));
        self::assertFalse($throttle->syncIfDue($sync, 'rAccount', 'testnet'));
        self::assertCount(1, $client->sentRequests(), 'one node request for two calls');

        $clock->advance(5);

        self::assertTrue($throttle->syncIfDue($sync, 'rAccount', 'testnet'));
        self::assertCount(2, $client->sentRequests());
    }

    private function emptyAccountTx(): Response
    {
        return new Response(200, [], json_encode([
            'result' => ['status' => 'success', 'transactions' => [], 'marker' => null],
        ]));
    }
}
