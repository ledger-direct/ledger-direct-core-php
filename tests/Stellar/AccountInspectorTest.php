<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Stellar\AccountInspector;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class AccountInspectorTest extends TestCase
{
    /** From the M0 fixture: the merchant trusts TESTUSD up to 1000 and holds 12.34. */
    public function testReadsTheTrustlineWithItsLimitAndHeadroom(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/accounts/' . StellarFixtures::MERCHANT, StellarFixtures::response('horizon/merchant-account.json'));
        $inspector = $this->inspector($client);

        $trustline = $inspector->trustline(StellarFixtures::MERCHANT, 'TESTUSD', StellarFixtures::PAYER, 'testnet');

        self::assertNotNull($trustline);
        self::assertSame('12.3400000', $trustline->balance);
        self::assertSame('1000.0000000', $trustline->limit);
        self::assertTrue($trustline->authorized);
        self::assertSame('987.66', $trustline->headroom());
        self::assertSame('https://horizon-testnet.stellar.org/accounts/' . StellarFixtures::MERCHANT, (string) $client->lastRequest()?->getUri());
    }

    public function testCanReceiveChecksTrustlineAndRoom(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/accounts/' . StellarFixtures::MERCHANT, StellarFixtures::response('horizon/merchant-account.json'));
        $inspector = $this->inspector($client, new InMemoryCache(new FrozenClock()));

        self::assertTrue($inspector->canReceive(StellarFixtures::MERCHANT, 'TESTUSD', StellarFixtures::PAYER, 'testnet'));
        self::assertTrue($inspector->canReceive(StellarFixtures::MERCHANT, 'TESTUSD', StellarFixtures::PAYER, 'testnet', '987.66'));
        self::assertFalse($inspector->canReceive(StellarFixtures::MERCHANT, 'TESTUSD', StellarFixtures::PAYER, 'testnet', '987.67'), 'op_line_full');
        self::assertFalse($inspector->canReceive(StellarFixtures::MERCHANT, 'USDC', 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5', 'testnet'), 'no trust line: op_no_trust');
        self::assertCount(1, $client->sentRequests(), 'one Horizon call, the rest from the cache');
    }

    public function testAnUnknownAccountDoesNotExist(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/accounts/GNOBODY', new Response(404, [], '{"status":404,"title":"Resource Missing"}'));
        $inspector = $this->inspector($client, new InMemoryCache(new FrozenClock()));

        self::assertFalse($inspector->accountExists('GNOBODY', 'testnet'));
        self::assertNull($inspector->trustline('GNOBODY', 'USDC', 'GISSUER', 'testnet'));
        self::assertCount(1, $client->sentRequests(), '"does not exist" is cached too');
    }

    /**
     * Circle's mainnet USDC issuer is auth_revocable (it can freeze a trust
     * line) and not clawback-enabled; the spike's test issuer has no flags.
     */
    public function testIssuerFlagsComeFromTheIssuerAccount(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/accounts/GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN', StellarFixtures::response('registry/mainnet-USDC-issuer.json'));
        $client->queueResponse('/accounts/' . StellarFixtures::PAYER, StellarFixtures::response('horizon/issuer-account.json'));
        $inspector = $this->inspector($client);

        $circle = $inspector->issuerFlags('GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN', 'mainnet');
        self::assertTrue($circle->authRevocable);
        self::assertFalse($circle->clawbackEnabled);
        self::assertFalse($circle->authRequired);
        self::assertTrue($circle->canFreezeOrClawBack());

        $test = $inspector->issuerFlags(StellarFixtures::PAYER, 'testnet');
        self::assertFalse($test->canFreezeOrClawBack());
    }

    public function testABrokenCacheIsLoggedAndBypassed(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/accounts/' . StellarFixtures::MERCHANT, StellarFixtures::response('horizon/merchant-account.json'));
        $cache = new InMemoryCache();
        $cache->failOn('get', 'set');
        $logger = new RecordingLogger();
        $inspector = new AccountInspector(new HorizonClient($client, new HttpFactory()), $logger, $cache);

        self::assertTrue($inspector->accountExists(StellarFixtures::MERCHANT, 'testnet'));
        self::assertSame(['warning', 'warning'], array_column($logger->records(), 'level'));
    }

    private function inspector(FakeHttpClient $client, ?InMemoryCache $cache = null): AccountInspector
    {
        return new AccountInspector(new HorizonClient($client, new HttpFactory()), new RecordingLogger(), $cache);
    }
}
