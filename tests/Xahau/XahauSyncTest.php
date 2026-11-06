<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xahau;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The XRPL sync, unchanged, on real Xahau testnet answers. Recorded by the M0
 * spike on 2026-11-06 against a merchant account with RequireDest:
 *
 * - the faucet's funding payment (no tag),
 * - tag 1000001: 1.5 XAH,
 * - tag 1000003: 0.4 + 0.6 XAH, a partial payment topped up,
 * - tag 1000004: 1.25 XAH as a Remit (no delivered_amount; not supported),
 * - tag 1000006: 2.5 of a token the payer issued itself,
 * - tag 1000005: a Remit with XAH and that token,
 *
 * plus the merchant's own AccountSet and TrustSet. Only public ledger data.
 */
final class XahauSyncTest extends TestCase
{
    private const MERCHANT = 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2';
    private const NODE = 'xahau-test.net';

    public function testStoresTheIncomingTransactionsFromTheXahauTestnet(): void
    {
        [$client, $repository, $service] = $this->makeService();
        $client->queueResponse(self::NODE, self::fixture('account-tx.json'));

        $service->syncTransactions(self::MERCHANT, 'testnet');

        $stored = $repository->storedTransactions();
        $tags = array_map(static fn (XrplTransaction $t): ?int => $t->destinationTag, $stored);
        sort($tags);

        // The merchant's own AccountSet and TrustSet are not incoming; everything else is,
        // the two Remits included — they are stored, they just never count (see below).
        self::assertSame([null, 1000001, 1000003, 1000003, 1000004, 1000005, 1000006], $tags);

        foreach ($stored as $transaction) {
            self::assertSame('testnet', $transaction->network);
            self::assertSame(self::MERCHANT, $transaction->destination);
            self::assertTrue(XrplTransaction::isValidCtid($transaction->ctid));
            self::assertStringEndsWith('535A', $transaction->ctid, 'network id 21338 in the CTID');
        }

        $request = json_decode((string) $client->sentRequests()[0]->getBody(), true);
        self::assertSame('account_tx', $request['method']);
        self::assertStringStartsWith('https://xahau-test.net/', (string) $client->sentRequests()[0]->getUri());
    }

    public function testAPaymentWithItsTagSettlesAnXahIntent(): void
    {
        $service = $this->synced();

        $fulfillment = $service->findFulfillmentFor($this->xahIntent(tag: 1000001, requested: 1.5));

        self::assertNotNull($fulfillment);
        self::assertSame(1.5, $fulfillment->amountPaid);
        self::assertSame(PaymentStatus::SETTLED, $this->state($fulfillment->applyTo($this->xahIntent(1000001, 1.5))));
    }

    public function testTwoPartialPaymentsOnOneTagAddUp(): void
    {
        $service = $this->synced();

        $fulfillment = $service->findFulfillmentFor($this->xahIntent(tag: 1000003, requested: 1.0));

        self::assertNotNull($fulfillment);
        self::assertSame(2, $fulfillment->count());
        self::assertSame(1.0, $fulfillment->amountPaid);
        self::assertSame(PaymentStatus::SETTLED, $this->state($fulfillment->applyTo($this->xahIntent(1000003, 1.0))));
    }

    public function testATokenOnAnXahQuoteIsAWrongAsset(): void
    {
        $service = $this->synced();

        $fulfillment = $service->findFulfillmentFor($this->xahIntent(tag: 1000006, requested: 2.5));

        self::assertNotNull($fulfillment);
        self::assertSame(PaymentStatus::WRONG_ASSET, $this->state($fulfillment->applyTo($this->xahIntent(1000006, 2.5))));
    }

    /**
     * Known gap (INVARIANTS.md, "Xahau"): a Remit carries no delivered_amount,
     * so it never counts. A checkout asks for a Payment and wallets send one
     * (Xaman, 2026-11-06); a Remit is built by hand.
     */
    public function testARemitNeverCounts(): void
    {
        $service = $this->synced();

        self::assertNull($service->findFulfillmentFor($this->xahIntent(tag: 1000004, requested: 1.25)));
        self::assertNull($service->findFulfillmentFor($this->xahIntent(tag: 1000005, requested: 0.75)));
    }

    public function testRecoversFromACursorTheXahauNodeCannotServe(): void
    {
        [$client, $repository, $service, $logger] = $this->makeService();
        $client->queueResponse(self::NODE, self::fixture('account-tx.json'));
        $service->syncTransactions(self::MERCHANT, 'testnet');

        // The testnet keeps about three weeks of history; a cursor older than that, or
        // one from before a reset, gets the same lgrIdxsInvalid as on the XRP Ledger.
        $client->queueResponse(self::NODE, self::fixture('account-tx-lgrIdxsInvalid.json'));
        $client->queueResponse(self::NODE, self::fixture('account-tx.json'));
        $service->syncTransactions(self::MERCHANT, 'testnet');

        $requests = $client->sentRequests();
        $cursorCall = json_decode((string) $requests[1]->getBody(), true);
        $retry = json_decode((string) $requests[2]->getBody(), true);
        self::assertArrayHasKey('ledger_index_min', $cursorCall['params'][0]);
        self::assertArrayNotHasKey('ledger_index_min', $retry['params'][0]);
        self::assertCount(7, $repository->storedTransactions(), 'the resync stores nothing twice');
        self::assertContains('warning', array_column($logger->records(), 'level'));
    }

    /**
     * @return array{0: FakeHttpClient, 1: InMemoryXrplTransactionRepository, 2: SyncService, 3: RecordingLogger}
     */
    private function makeService(): array
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $logger = new RecordingLogger();
        $service = new SyncService(new XrplClient($client, new HttpFactory(), new HttpFactory(), 'XAHAU'), $repository, $logger);

        return [$client, $repository, $service, $logger];
    }

    private function synced(): SyncService
    {
        [$client, , $service] = $this->makeService();
        $client->queueResponse(self::NODE, self::fixture('account-tx.json'));
        $service->syncTransactions(self::MERCHANT, 'testnet');

        return $service;
    }

    private function xahIntent(int $tag, float $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xahau-xah-payment',
            chain: 'XAHAU',
            network: 'testnet',
            baseAsset: 'XAH',
            quoteCurrency: 'USD',
            pairing: 'XAH/USD',
            exchangeRate: 0.0101,
            amountRequested: $requested,
            destinationAccount: self::MERCHANT,
            destinationTag: $tag,
        );
    }

    private function state(PaymentIntent $intent): string
    {
        return PaymentStatus::fromIntent($intent, new SettlementPolicy())->state();
    }

    private static function fixture(string $name): Response
    {
        $body = file_get_contents(__DIR__ . '/fixtures/' . $name);
        if ($body === false) {
            throw new RuntimeException("Missing fixture {$name}.");
        }

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
