<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonException;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class HorizonClientTest extends TestCase
{
    private const MERCHANT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';
    private const ISSUER = 'GDZN7JUZHAJEULYCSOQFON5DC26Z6XOH7AB45LIHLXQCQS5MFUW4QN5C';

    /**
     * The M0 fixture: eight records on the account, seven of them payments
     * to it — the eighth is the account's own funding (`create_account`).
     */
    public function testFetchPaymentsMapsEveryCaseFromTheFixture(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));

        $page = (new HorizonClient($client, new HttpFactory()))->fetchPayments(self::MERCHANT, 'testnet', null);

        $url = (string) $client->lastRequest()?->getUri();
        self::assertStringStartsWith('https://horizon-testnet.stellar.org/accounts/' . self::MERCHANT . '/payments?', $url);
        self::assertStringContainsString('join=transactions', $url);
        self::assertStringContainsString('order=asc', $url);
        self::assertStringContainsString('limit=200', $url);
        self::assertStringNotContainsString('cursor=', $url);
        self::assertSame('application/json', $client->lastRequest()?->getHeaderLine('Accept'));

        self::assertCount(7, $page['payments']);
        self::assertSame('20741097152057345', $page['cursor'], 'the last record\'s paging_token, create_account included');
        self::assertTrue($page['exhausted']);

        /** @var array<string, StellarPayment> $byHash */
        $byHash = [];
        foreach ($page['payments'] as $payment) {
            $byHash[$payment->hash . ':' . $payment->opIndex] = $payment;
        }

        $memoId = $byHash['cebc6a6e4ba40b0c1b8bf86e8b23301ac2a2f7335ca842ecdc8f9156e73322b9:1'];
        self::assertSame('123456', $memoId->memoId);
        self::assertSame('XLM', $memoId->assetCode);
        self::assertNull($memoId->assetIssuer);
        self::assertSame('1.5000000', $memoId->amount);
        self::assertSame('payment', $memoId->type);
        self::assertSame(self::ISSUER, $memoId->account);
        self::assertSame(self::MERCHANT, $memoId->destination);
        self::assertSame(4829157, $memoId->ledger());
        self::assertSame(1790169372, $memoId->createdAt);

        $muxed = $byHash['eeb71d4e41fda7e2ea0f2eafb4c32876babacaace277929a653a354c0fd2323e:1'];
        self::assertSame('654321', $muxed->memoId, 'the muxed id wins, and the transaction has no memo');

        $path = $byHash['76087cf5335bcff8892748151be9ae3f42b2e41ead7fdfd562422df01680eb23:1'];
        self::assertSame('path_payment_strict_send', $path->type);
        self::assertSame('0.7000000', $path->amount, 'what arrived, not source_amount');
        self::assertSame('123456', $path->memoId);

        self::assertSame('777', $byHash['9a5a2cf4e28e18bc0848dff7a904c9e12b676ad8d7568a7c5ce7696990cce4a5:1']->memoId);
        self::assertSame('0.7500000', $byHash['9a5a2cf4e28e18bc0848dff7a904c9e12b676ad8d7568a7c5ce7696990cce4a5:2']->amount);

        self::assertSame('424242', $byHash['d31e17ed8850c178e205315e1c8aaf5f2fbc190b4b43c448bc2b153cfdeacc82:1']->memoId, 'a canonical MEMO_TEXT counts');

        $issued = $byHash['5c5f9fe6de7e29a832cb8c7718125b9548291af52c40c1204aa97006936bafe1:1'];
        self::assertSame('TESTUSD', $issued->assetCode);
        self::assertSame(self::ISSUER, $issued->assetIssuer);
        self::assertSame('12.3400000', $issued->amount);
    }

    public function testFetchPaymentsSendsTheCursorAndAnEmptyPageIsExhausted(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-after-cursor.json'));

        $page = (new HorizonClient($client, new HttpFactory()))->fetchPayments(self::MERCHANT, 'testnet', '20741097152057345');

        self::assertStringContainsString('cursor=20741097152057345', (string) $client->lastRequest()?->getUri());
        self::assertSame([], $page['payments']);
        self::assertSame('20741097152057345', $page['cursor'], 'an empty page keeps the cursor');
        self::assertTrue($page['exhausted']);
    }

    public function testLatestLedgerComesFromTheRootDocument(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('horizon-testnet.stellar.org/', StellarFixtures::response('horizon/root.json'));

        self::assertSame(4829162, (new HorizonClient($client, new HttpFactory()))->latestLedger('testnet'));
        self::assertSame('https://horizon-testnet.stellar.org/', (string) $client->lastRequest()?->getUri());
    }

    public function testARateLimitIsAnExceptionThatCarriesRetryAfter(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/payments', new Response(429, ['Retry-After' => '7'], '{}'));

        try {
            (new HorizonClient($client, new HttpFactory()))->fetchPayments(self::MERCHANT, 'testnet', null);
            self::fail('Expected HorizonException.');
        } catch (HorizonException $exception) {
            self::assertTrue($exception->isRateLimited());
            self::assertSame(429, $exception->status);
            self::assertSame(7, $exception->retryAfter);
        }
    }

    public function testOtherFailuresAreExceptionsToo(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('/payments', new Response(503, [], ''));
        $client->queueResponse('/payments', new Response(200, [], 'not json'));
        $horizon = new HorizonClient($client, new HttpFactory());

        try {
            $horizon->fetchPayments(self::MERCHANT, 'testnet', null);
            self::fail('Expected HorizonException.');
        } catch (HorizonException $exception) {
            self::assertSame(503, $exception->status);
            self::assertFalse($exception->isRateLimited());
        }

        $this->expectException(HorizonException::class);
        $horizon->fetchPayments(self::MERCHANT, 'testnet', null);
    }

    public function testMainnetAndTestnetHaveFixedEndpointsAndNothingElse(): void
    {
        self::assertSame('https://horizon.stellar.org', HorizonClient::URLS['mainnet']);
        self::assertSame('https://horizon-testnet.stellar.org', HorizonClient::URLS['testnet']);

        $this->expectException(\InvalidArgumentException::class);
        (new HorizonClient(new FakeHttpClient(), new HttpFactory()))->latestLedger('futurenet');
    }
}
