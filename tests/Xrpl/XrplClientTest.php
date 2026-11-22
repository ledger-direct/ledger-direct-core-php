<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    public function testAccountInfoIsNullForAnAccountThatDoesNotExist(): void
    {
        $node = (new FakeNode())->answer('account_info', 'unactivated-account-info.json');
        $xrplClient = new XrplClient($node, new HttpFactory(), new HttpFactory());

        self::assertNull($xrplClient->accountInfo('rNALbYJqWYLawAXAuK6wj8kpWcJn2e6uvv', 'testnet'));
        self::assertSame(['account' => 'rNALbYJqWYLawAXAuK6wj8kpWcJn2e6uvv', 'ledger_index' => 'validated'], $node->calls[0]['params']);
    }

    public function testAccountInfoReturnsTheAccountRoot(): void
    {
        $node = (new FakeNode())->answer('account_info', 'merchant-account-info.json');
        $root = (new XrplClient($node, new HttpFactory(), new HttpFactory()))->accountInfo('rBMBL9zS34zGeVUSk9fHAZLQvL24RuC3WY', 'testnet');

        self::assertSame('rBMBL9zS34zGeVUSk9fHAZLQvL24RuC3WY', $root['Account'] ?? null);
    }

    public function testAccountLinesFollowsTheMarkerOnTheFirstPagesLedgerAndStops(): void
    {
        $node = new class implements ClientInterface {
            public int $calls = 0;

            /** @var list<mixed> */
            public array $ledgers = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;
                $params = json_decode((string) $request->getBody(), true)['params'][0];
                $this->ledgers[] = $params['ledger_index'];
                // A new ledger validates between the pages; the second page must not follow it.
                $result = isset($params['marker'])
                    ? ['status' => 'success', 'ledger_index' => 101, 'lines' => [['account' => 'rB', 'currency' => 'USD']]]
                    : ['status' => 'success', 'ledger_index' => 100, 'lines' => [['account' => 'rA', 'currency' => 'USD']], 'marker' => 'next'];

                return new Response(200, [], (string) json_encode(['result' => $result]));
            }
        };

        $lines = (new XrplClient($node, new HttpFactory(), new HttpFactory()))->accountLines('rMerchant', 'testnet');

        self::assertSame(['rA', 'rB'], array_column($lines, 'account'));
        self::assertSame(2, $node->calls);
        self::assertSame(['validated', 100], $node->ledgers);
    }

    public function testAccountLinesGivesUpOnANodeThatNeverEnds(): void
    {
        $node = (new FakeNode())->answer('account_lines', ['status' => 'success', 'lines' => [], 'marker' => 'again']);

        $this->expectException(XrplRpcException::class);

        (new XrplClient($node, new HttpFactory(), new HttpFactory()))->accountLines('rMerchant', 'testnet');
    }

    public function testReservesComeFromTheValidatedLedgerAndTheParametersGoOutAsAnObject(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse('s.altnet.rippletest.net', new Response(200, [], (string) file_get_contents(FakeNode::FIXTURES . 'server-state.json')));

        $reserves = (new XrplClient($client, new HttpFactory(), new HttpFactory()))->reserves('testnet');

        self::assertSame(['base' => '1000000', 'increment' => '200000'], $reserves);
        self::assertStringContainsString('"params":[{}]', (string) $client->lastRequest()?->getBody());
    }

    public function testTheValidatedLedgerIndexComesFromServerState(): void
    {
        $node = (new FakeNode())->answer('server_state', 'server-state.json');

        self::assertSame(22396432, (new XrplClient($node, new HttpFactory(), new HttpFactory()))->validatedLedgerIndex('testnet'));
        self::assertSame(1, $node->count('server_state'));
    }

    public function testTheValidatedLedgerIndexOnXahauComesFromXahausNode(): void
    {
        $node = (new FakeNode())->answer('server_state', 'xahau-server-state.json');

        $index = (new XrplClient($node, new HttpFactory(), new HttpFactory(), 'XAHAU'))->validatedLedgerIndex('testnet');

        self::assertSame(14139507, $index);
        self::assertSame('https://xahau-test.net/', $node->calls[0]['url']);
    }

    /**
     * A node that is still syncing reports no validated ledger. That is no index, not index 0:
     * a lower bound of 0 would make a caller read an account's whole history.
     */
    public function testANodeWithoutAValidatedLedgerHasNoIndexToGive(): void
    {
        $state = FakeNode::result('server-state.json');
        unset($state['state']['validated_ledger']);
        $node = (new FakeNode())->answer('server_state', $state);

        $this->expectException(XrplRpcException::class);

        (new XrplClient($node, new HttpFactory(), new HttpFactory()))->validatedLedgerIndex('testnet');
    }

    public function testAServerStateErrorIsNotAnIndex(): void
    {
        $node = (new FakeNode())->answer('server_state', ['status' => 'error', 'error' => 'noNetwork']);

        try {
            (new XrplClient($node, new HttpFactory(), new HttpFactory()))->validatedLedgerIndex('testnet');
            self::fail('An error answer must not yield an index.');
        } catch (XrplRpcException $exception) {
            self::assertSame('noNetwork', $exception->error);
        }
    }

    public function testUnsupportedNetworkThrows(): void
    {
        $xrplClient = new XrplClient(new FakeHttpClient(), new HttpFactory(), new HttpFactory());

        $this->expectException(InvalidArgumentException::class);

        $xrplClient->fetchAccountTransactions('rSomeAddress', 'devnet');
    }
}
