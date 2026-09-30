<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use PHPUnit\Framework\TestCase;

final class LedgerDirectStellarTest extends TestCase
{
    public function testEveryGetterHandsOutTheSameInstance(): void
    {
        $core = $this->create(cache: new InMemoryCache());

        self::assertSame($core->horizonClient(), $core->horizonClient());
        self::assertSame($core->priceService(), $core->priceService());
        self::assertSame($core->syncService(), $core->syncService());
        self::assertSame($core->memoIdService(), $core->memoIdService());
        self::assertSame($core->paymentIntentService(), $core->paymentIntentService());
        self::assertSame($core->settlementPolicy(), $core->settlementPolicy());
        self::assertInstanceOf(SyncThrottle::class, $core->syncThrottle());
        self::assertNull($this->create()->syncThrottle());
    }

    /**
     * The golden path, end to end against the recorded testnet answers: an
     * order quoted in XLM, two payments on its memo, a throttled sync, and
     * the status the payment page shows.
     */
    public function testTheGoldenPathFromQuoteToSettled(): void
    {
        $client = new FakeHttpClient();
        $this->queueXlmOraclePrice($client, '0.40');
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));
        $clock = new FrozenClock(1_700_000_000);
        $repository = new InMemoryStellarPaymentRepository();
        $core = $this->create(client: $client, repository: $repository, cache: new InMemoryCache($clock), clock: $clock);

        // The quote: 0.88 USD at 0.40 USD/XLM is the 2.2 XLM the fixtures paid on memo 123456.
        $quoted = $core->paymentIntentService()->quoteForOrder(0.88, 'USD', 'XLM');
        self::assertSame(2.2, $quoted->amountRequested);
        $intent = PaymentIntent::fromArray(['destination_tag' => 123456] + $quoted->toArray());

        self::assertSame(PaymentStatus::WAITING, $core->paymentStatus($intent)->state());

        // The status endpoint: sync once per interval, then judge.
        self::assertTrue($core->syncThrottle()->syncIfDue($core->syncService(), StellarFixtures::MERCHANT, 'testnet'));
        self::assertFalse($core->syncThrottle()->syncIfDue($core->syncService(), StellarFixtures::MERCHANT, 'testnet'), 'throttled');

        $fulfilled = $core->syncService()->findFulfillmentFor($intent)?->applyTo($intent);
        $status = $core->paymentStatus($fulfilled);

        self::assertSame(PaymentStatus::SETTLED, $status->state());
        self::assertTrue($status->isTerminal());
        self::assertSame(2.2, $status->toArray()['amount_paid']);
        self::assertSame('76087cf5335bcff8892748151be9ae3f42b2e41ead7fdfd562422df01680eb23', $fulfilled->hash);
        self::assertCount(4, $client->sentRequests(), 'three oracles and one Horizon page');
    }

    private function create(
        ?FakeHttpClient $client = null,
        ?InMemoryStellarPaymentRepository $repository = null,
        ?InMemoryCache $cache = null,
        ?FrozenClock $clock = null,
    ): LedgerDirectStellar {
        return LedgerDirectStellar::create(
            $client ?? new FakeHttpClient(),
            new HttpFactory(),
            new RecordingLogger(),
            $repository ?? new InMemoryStellarPaymentRepository(),
            new FakeConfigProvider(destinationAccount: StellarFixtures::MERCHANT),
            $cache,
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
