<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Hardcastle\LedgerDirect\Core\Stellar\SyncService;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase
{
    public function testAFirstSyncStoresEveryPaymentToTheAccountAndNothingElse(): void
    {
        [$client, $repository, $service] = $this->makeService();
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));

        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        self::assertCount(7, $repository->storedPayments(), 'eight records, one of them the account\'s own create_account');
        self::assertCount(1, $client->sentRequests(), 'no cursor stored, so no root lookup; one page, exhausted');
        self::assertSame('20741097152057345', $repository->getLastPaymentId(StellarFixtures::MERCHANT, 'testnet'));
    }

    public function testASecondSyncSendsTheCursorAfterCheckingItAgainstTheNetwork(): void
    {
        [$client, $repository, $service] = $this->makeService();
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));
        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        // Root first (the cursor check), then the page after the cursor. The fixture's root
        // document reports 4,829,162 while the cursor is from ledger 4,829,163 — Horizon lagged
        // by one when the spike ran. That is ingestion latency, not a reset, and must pass.
        $client->queueResponse('horizon-testnet.stellar.org/', StellarFixtures::response('horizon/root.json'));
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-after-cursor.json'));
        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        $requests = $client->sentRequests();
        self::assertCount(3, $requests);
        self::assertSame('https://horizon-testnet.stellar.org/', (string) $requests[1]->getUri());
        self::assertStringContainsString('cursor=20741097152057345', (string) $requests[2]->getUri());
        self::assertCount(7, $repository->storedPayments(), 'nothing duplicated');
    }

    /**
     * Horizon answers a cursor from a previous testnet epoch with 200 and
     * no records — no error to catch. The cursor's own ledger bits give it
     * away: a ledger above the network's newest cannot be ours.
     */
    public function testACursorAheadOfTheNetworkIsRecognisedAsAResetAndDropped(): void
    {
        [$client, $repository, $service, $logger] = $this->makeService();
        // A row from a previous epoch: ledger 99,000,000, far above the fixture's 4,829,162.
        $repository->savePayments([$this->stalePayment(ledger: 99_000_000)]);

        $client->queueResponse('horizon-testnet.stellar.org/', StellarFixtures::response('horizon/root.json'));
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));

        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        $pageRequest = (string) $client->sentRequests()[1]->getUri();
        self::assertStringNotContainsString('cursor=', $pageRequest, 'resynced from the beginning');
        self::assertCount(8, $repository->storedPayments(), 'the stale row stays, the seven real ones arrive');
        self::assertSame('warning', $logger->records()[0]['level']);
        self::assertStringContainsString('ahead of the network', $logger->records()[0]['message']);
    }

    public function testASyncedPaymentIsNotStoredTwiceEvenWhenHorizonSendsItAgain(): void
    {
        [$client, $repository, $service] = $this->makeService();
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));
        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        // Reset detected → full resync → Horizon sends the same eight records again.
        $repository->savePayments([$this->stalePayment(ledger: 99_000_000)]);
        $client->queueResponse('horizon-testnet.stellar.org/', StellarFixtures::response('horizon/root.json'));
        $client->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));
        $service->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        self::assertCount(8, $repository->storedPayments(), '7 real + 1 stale, no duplicates');
    }

    /* ---- findFulfillmentFor(): the settlement rule on Stellar ---- */

    public function testTwoXlmPaymentsOnTheMemoAddUpAndSettleTheOrder(): void
    {
        [, , $service, $logger] = $this->syncedService();
        $intent = $this->xlmIntent(2.2, memoId: 123456);

        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertNotNull($fulfillment);
        self::assertSame(2, $fulfillment->count(), '1.5 by payment + 0.7 by path payment; the TESTUSD payment does not count');
        self::assertSame(2.2, $fulfillment->amountPaid);
        self::assertSame('76087cf5335bcff8892748151be9ae3f42b2e41ead7fdfd562422df01680eb23', $fulfillment->hash(), 'the newest contributing one');

        $fulfilled = $fulfillment->applyTo($intent);
        self::assertNull($fulfilled->ctid);
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($fulfilled, new SettlementPolicy())->state());

        $ignored = array_filter($logger->records(), static fn (array $r): bool => str_contains($r['message'], 'ignored, the order has payments in the quoted asset'));
        self::assertCount(1, $ignored, 'the TESTUSD payment is put aside and logged, not silently dropped');
        self::assertSame('5c5f9fe6', substr(array_values($ignored)[0]['context']['hash'], 0, 8));
    }

    public function testTwoOperationsOfOneTransactionAreTwoContributions(): void
    {
        [, , $service] = $this->syncedService();
        $intent = $this->xlmIntent(1.0, memoId: 777);

        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertSame(2, $fulfillment?->count());
        self::assertSame(1.0, $fulfillment?->amountPaid);
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy())->state());
    }

    public function testAMuxedDestinationAndACanonicalTextMemoBothIdentifyTheOrder(): void
    {
        [, , $service] = $this->syncedService();

        self::assertSame(2.0, $service->findFulfillmentFor($this->xlmIntent(2.0, memoId: 654321))?->amountPaid);
        self::assertSame(0.1, $service->findFulfillmentFor($this->xlmIntent(0.1, memoId: 424242))?->amountPaid);
    }

    public function testAnUnderpaymentIsPartialWithTheShortfall(): void
    {
        [, , $service] = $this->syncedService();
        $intent = $this->xlmIntent(5.0, memoId: 123456);

        $status = PaymentStatus::fromIntent($service->findFulfillmentFor($intent)->applyTo($intent), new SettlementPolicy());

        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertSame(2.2, $status->toArray()['amount_paid']);
        self::assertSame(2.8, $status->toArray()['shortfall']);
    }

    /**
     * An issued-asset quote paid with another issued asset: nothing in the
     * quoted asset, so the newest foreign payment alone is the fulfillment
     * and the page can say wrong_asset. The two XLM payments on the same
     * memo are foreign as well (the other class), older, and not logged:
     * with nothing in the quoted asset there is nothing to put them aside for.
     */
    public function testAPaymentInAnotherIssuedAssetAloneIsWrongAsset(): void
    {
        [, , $service, $logger] = $this->syncedService();
        $intent = $this->issuedIntent('USDC', 'GCIRCLEISSUER', '10', memoId: 123456);

        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertSame(1, $fulfillment?->count());
        self::assertSame(['currency' => 'TESTUSD', 'value' => '12.34', 'issuer' => StellarFixtures::PAYER], $fulfillment?->amountPaid);

        $status = PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy());
        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
        self::assertSame('10', $status->toArray()['shortfall']['value']);

        $aboutAssets = array_filter($logger->records(), static fn (array $r): bool => str_contains($r['message'], 'asset'));
        self::assertSame([], $aboutAssets, 'no payment on this memo is skipped or ignored');
    }

    /**
     * The case that used to vanish on this chain too: a USDC order paid
     * with XLM. The money is on the memo, the wallet said success, and the
     * page said "waiting" until the quote expired because the sync skipped
     * the payment as the other class. It is a wrong-asset payment like any
     * other: nothing credited, the whole request still due, the delivered
     * amount visible in its own shape.
     */
    public function testXlmAloneOnAnIssuedQuoteIsTheFulfillmentSoWrongAssetIsReachableAcrossClasses(): void
    {
        [, , $service, $logger] = $this->syncedService();
        $intent = $this->issuedIntent('USDC', 'GCIRCLEISSUER', '10', memoId: 654321);

        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertSame(1, $fulfillment?->count());
        self::assertSame(2.0, $fulfillment?->amountPaid, 'the 2 XLM to the muxed destination, as a float');

        $status = PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy());
        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
        self::assertSame(2.0, $status->toArray()['amount_paid']);
        self::assertSame(['currency' => 'USDC', 'value' => '10', 'issuer' => 'GCIRCLEISSUER'], $status->toArray()['shortfall'], 'the whole request, in the requested shape');
        self::assertSame([], $logger->records());
    }

    public function testAnIssuedAssetAloneOnAnXlmQuoteIsTheFulfillmentSoWrongAssetIsReachableAcrossClasses(): void
    {
        [, $repository, $service] = $this->makeService();
        $repository->savePayments([
            $this->storedPayment('HASH_TESTUSD_FOR_XLM', ledger: 4900, memoId: 31337, assetCode: 'TESTUSD', assetIssuer: StellarFixtures::PAYER, amount: '8.0000000'),
        ]);

        $intent = $this->xlmIntent(20.0, memoId: 31337);
        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertSame('HASH_TESTUSD_FOR_XLM', $fulfillment?->hash());
        self::assertSame(['currency' => 'TESTUSD', 'value' => '8', 'issuer' => StellarFixtures::PAYER], $fulfillment?->amountPaid, 'the delivered asset, unchanged');

        $status = PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy());
        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
        self::assertSame(20.0, $status->toArray()['shortfall'], 'the whole request, in the requested shape');
        self::assertSame('8', $status->toArray()['amount_paid']['value']);
    }

    public function testOnceTheQuotedClassArrivesOnlyItCountsEvenIfTheOtherClassCameFirst(): void
    {
        [, $repository, $service, $logger] = $this->makeService();
        $repository->savePayments([
            $this->storedPayment('HASH_TESTUSD_FIRST', ledger: 4800, memoId: 31337, assetCode: 'TESTUSD', assetIssuer: StellarFixtures::PAYER, amount: '8.0000000'),
            $this->storedPayment('HASH_XLM_LATER', ledger: 4900, memoId: 31337, assetCode: 'XLM', assetIssuer: null, amount: '12.5000000'),
        ]);

        $intent = $this->xlmIntent(20.0, memoId: 31337);
        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertSame(1, $fulfillment?->count(), 'the TESTUSD payment does not add to the XLM');
        self::assertSame('HASH_XLM_LATER', $fulfillment?->hash());

        $status = PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy());
        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertSame(12.5, $status->toArray()['amount_paid']);
        self::assertSame(7.5, $status->toArray()['shortfall']);

        $ignored = array_filter($logger->records(), static fn (array $r): bool => str_contains($r['message'], 'ignored, the order has payments in the quoted asset'));
        self::assertSame(['HASH_TESTUSD_FIRST'], array_column(array_column(array_values($ignored), 'context'), 'hash'));
    }

    public function testTheQuotedIssuedAssetSettles(): void
    {
        [, , $service] = $this->syncedService();
        $intent = $this->issuedIntent('TESTUSD', StellarFixtures::PAYER, '12.34', memoId: 123456);

        $fulfilled = $service->findFulfillmentFor($intent)?->applyTo($intent);

        self::assertSame(['currency' => 'TESTUSD', 'value' => '12.34', 'issuer' => StellarFixtures::PAYER], $fulfilled?->amountPaid);
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($fulfilled, new SettlementPolicy())->state());
    }

    /**
     * The G-address is an account on both networks and the memo counter is
     * per account: a testnet XLM payment on a refreshed mainnet quote must
     * neither settle it nor show as wrong_asset. Same rule as on XRPL.
     */
    public function testAPaymentFromAnotherNetworkNeverSettlesOrCountsAtAll(): void
    {
        [, $repository, $service, $logger] = $this->makeService();
        $repository->savePayments([
            $this->storedPayment('HASH_TESTNET_XLM', ledger: 4900, memoId: 31337, assetCode: 'XLM', assetIssuer: null, amount: '20.0000000'),
        ]);

        $mainnetIntent = PaymentIntent::fromArray(array_replace($this->xlmIntent(20.0, memoId: 31337)->toArray(), ['network' => 'mainnet']));

        self::assertNull($service->findFulfillmentFor($mainnetIntent));
        self::assertSame(PaymentStatus::WAITING, PaymentStatus::fromIntent($mainnetIntent, new SettlementPolicy())->state());
        self::assertCount(1, array_filter($logger->records(), static fn (array $r): bool => str_contains($r['message'], 'another network')));

        $fulfilled = $service->findFulfillmentFor($this->xlmIntent(20.0, memoId: 31337))?->applyTo($this->xlmIntent(20.0, memoId: 31337));
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($fulfilled, new SettlementPolicy())->state(), 'on its own network the same payment settles');
    }

    public function testNoPaymentOnTheMemoMeansNoFulfillment(): void
    {
        [, , $service] = $this->syncedService();

        self::assertNull($service->findFulfillmentFor($this->xlmIntent(1.0, memoId: 999999)));
    }

    /**
     * @return array{0: FakeHttpClient, 1: InMemoryStellarPaymentRepository, 2: SyncService, 3: RecordingLogger}
     */
    private function makeService(): array
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryStellarPaymentRepository();
        $logger = new RecordingLogger();
        $service = new SyncService(new HorizonClient($client, new HttpFactory()), $repository, $logger);

        return [$client, $repository, $service, $logger];
    }

    /**
     * @return array{0: FakeHttpClient, 1: InMemoryStellarPaymentRepository, 2: SyncService, 3: RecordingLogger}
     */
    private function syncedService(): array
    {
        $parts = $this->makeService();
        $parts[0]->queueResponse('/payments', StellarFixtures::response('horizon/merchant-payments-joined.json'));
        $parts[2]->syncPayments(StellarFixtures::MERCHANT, 'testnet');

        return $parts;
    }

    private function storedPayment(string $hash, int $ledger, int $memoId, string $assetCode, ?string $assetIssuer, string $amount): StellarPayment
    {
        return new StellarPayment(
            network: 'testnet',
            paymentId: (string) (($ledger << 32) | (1 << 12) | 1),
            hash: $hash,
            opIndex: 1,
            type: 'payment',
            account: StellarFixtures::PAYER,
            destination: StellarFixtures::MERCHANT,
            memoId: (string) $memoId,
            assetCode: $assetCode,
            assetIssuer: $assetIssuer,
            amount: $amount,
            createdAt: 1700000000 + $ledger,
            raw: [],
        );
    }

    private function stalePayment(int $ledger): StellarPayment
    {
        return new StellarPayment(
            network: 'testnet',
            paymentId: (string) (($ledger << 32) | (1 << 12) | 1),
            hash: 'STALE_EPOCH_HASH',
            opIndex: 1,
            type: 'payment',
            account: StellarFixtures::PAYER,
            destination: StellarFixtures::MERCHANT,
            memoId: null,
            assetCode: 'XLM',
            assetIssuer: null,
            amount: '1.0000000',
            createdAt: 1700000000,
            raw: [],
        );
    }

    private function xlmIntent(float $requested, int $memoId): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'stellar-xlm-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: 'XLM',
            quoteCurrency: 'USD',
            pairing: 'XLM/USD',
            exchangeRate: 0.4,
            amountRequested: $requested,
            destinationAccount: StellarFixtures::MERCHANT,
            destinationTag: $memoId,
        );
    }

    private function issuedIntent(string $code, string $issuer, string $value, int $memoId): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'stellar-' . strtolower($code) . '-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: $code,
            quoteCurrency: 'USD',
            pairing: $code . '/USD',
            exchangeRate: 1.0,
            amountRequested: ['currency' => $code, 'value' => $value, 'issuer' => $issuer],
            destinationAccount: StellarFixtures::MERCHANT,
            destinationTag: $memoId,
        );
    }
}
