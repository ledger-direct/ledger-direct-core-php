<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XrplClientTest extends TestCase
{
    public function testFetchAccountTransactionsReturnsTransactionsAndMarker(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(200, [], json_encode([
            'result' => [
                'status' => 'success',
                'transactions' => [['tx' => ['hash' => 'ABC123']]],
                'marker' => 'next-page-marker',
            ],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $page = $xrplClient->fetchAccountTransactions('rSomeAddress', 'testnet');

        self::assertSame([['tx' => ['hash' => 'ABC123']]], $page['transactions']);
        self::assertSame('next-page-marker', $page['marker']);
    }

    public function testEmptyTransactionsIsANormalResultNotAnException(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(200, [], json_encode([
            'result' => ['status' => 'success', 'transactions' => [], 'marker' => null],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $page = $xrplClient->fetchAccountTransactions('rSomeAddress', 'testnet');

        self::assertSame([], $page['transactions']);
        self::assertNull($page['marker']);
    }

    public function testAfterLedgerIndexIsSentAsLedgerIndexMinPlusOne(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(200, [], json_encode([
            'result' => ['status' => 'success', 'transactions' => [], 'marker' => null],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());
        $xrplClient->fetchAccountTransactions('rSomeAddress', 'testnet', afterLedgerIndex: 100);

        $sentBody = json_decode((string) $client->lastRequest()?->getBody(), true);

        self::assertSame(101, $sentBody['params'][0]['ledger_index_min']);
    }

    public function testNonTwoHundredStatusThrows(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(503, [], 'Service Unavailable'));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $this->expectException(XrplRpcException::class);

        $xrplClient->fetchAccountTransactions('rSomeAddress', 'testnet');
    }

    public function testEmbeddedRpcErrorThrows(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(200, [], json_encode([
            'result' => ['status' => 'error', 'error' => 'actNotFound'],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $this->expectException(XrplRpcException::class);

        $xrplClient->fetchAccountTransactions('rSomeAddress', 'testnet');
    }

    public function testTxReturnsTheResultOnSuccess(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('xrplcluster.com', new Response(200, [], json_encode([
            'result' => ['status' => 'success', 'hash' => 'ABC123', 'validated' => true],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $result = $xrplClient->tx('ABC123', 'mainnet');

        self::assertSame('ABC123', $result['hash']);
        self::assertTrue($result['validated']);
    }

    public function testTxReturnsNullOnTxnNotFound(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('xrplcluster.com', new Response(200, [], json_encode([
            'result' => ['status' => 'error', 'error' => 'txnNotFound'],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        self::assertNull($xrplClient->tx('doesNotExist', 'mainnet'));
    }

    public function testTxThrowsOnAnyOtherEmbeddedError(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('xrplcluster.com', new Response(200, [], json_encode([
            'result' => ['status' => 'error', 'error' => 'invalidParams'],
        ])));

        $xrplClient = new XrplClient($client, new HttpFactory(), new HttpFactory());

        $this->expectException(XrplRpcException::class);

        $xrplClient->tx('someHash', 'mainnet');
    }

    public function testUnsupportedNetworkThrows(): void
    {
        $xrplClient = new XrplClient(new FakeHttpClient(), new HttpFactory(), new HttpFactory());

        $this->expectException(InvalidArgumentException::class);

        $xrplClient->fetchAccountTransactions('rSomeAddress', 'devnet');
    }
}
