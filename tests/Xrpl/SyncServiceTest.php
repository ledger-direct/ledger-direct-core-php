<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase
{
    private const OWN_ADDRESS = 'rOwnAddress';

    private const RLUSD_CURRENCY = '524C555344000000000000000000000000000000';

    private const RLUSD_ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    public function testSyncStoresOnlyIncomingTransactions(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_IN', account: 'rSender', destination: self::OWN_ADDRESS),
            $this->rawTx(hash: 'HASH_OUT', account: self::OWN_ADDRESS, destination: 'rSomeoneElse'),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        self::assertSame(['HASH_IN'], $hashes);
    }

    public function testDoesNotDuplicateAnAlreadyStoredHash(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_EXISTING', ledgerIndex: '100')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_EXISTING', account: 'rSender', destination: self::OWN_ADDRESS),
            $this->rawTx(hash: 'HASH_NEW', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        self::assertCount(2, $repository->storedTransactions());
    }

    public function testFollowsPaginationAcrossMultiplePages(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse(
            [$this->rawTx(hash: 'HASH_PAGE_1', account: 'rSender', destination: self::OWN_ADDRESS)],
            marker: 'page-2-marker',
        ));
        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse(
            [$this->rawTx(hash: 'HASH_PAGE_2', account: 'rSender', destination: self::OWN_ADDRESS)],
        ));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        sort($hashes);
        self::assertSame(['HASH_PAGE_1', 'HASH_PAGE_2'], $hashes);
    }

    public function testResumesFromTheLastSyncedLedgerIndex(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_OLD', ledgerIndex: '500')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $sentBody = json_decode((string) $client->lastRequest()?->getBody(), true);
        self::assertSame(501, $sentBody['params'][0]['ledger_index_min']);
    }

    /**
     * A single mainnet row (ledger index ~100 million) used to pin the
     * global cursor above every testnet ledger, so the testnet sync asked
     * for a range that does not exist and silently returned nothing —
     * permanently, with no way out but editing the database.
     */
    public function testTheCursorIgnoresRowsFromAnotherNetwork(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(hash: 'HASH_MAINNET', ledgerIndex: '100000000', network: 'mainnet'),
            $this->hydrated(hash: 'HASH_TESTNET', ledgerIndex: '500', network: 'testnet'),
        ]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $sentBody = json_decode((string) $client->lastRequest()?->getBody(), true);
        self::assertSame(501, $sentBody['params'][0]['ledger_index_min']);
    }

    public function testTheCursorIgnoresRowsForAnotherDestinationAccount(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(hash: 'HASH_OTHER_ACCOUNT', ledgerIndex: '900000', destination: 'rPreviousAddress'),
            $this->hydrated(hash: 'HASH_OURS', ledgerIndex: '500'),
        ]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $sentBody = json_decode((string) $client->lastRequest()?->getBody(), true);
        self::assertSame(501, $sentBody['params'][0]['ledger_index_min']);
    }

    /**
     * The testnet reset case. Rows from the previous epoch sat at ledger
     * index ~45 million while the live testnet was back at ~20.5 million,
     * so every sync failed with lgrIdxsInvalid — for every order, forever,
     * until the rows were deleted by hand.
     */
    public function testRecoversFromATestnetResetByResyncingWithoutACursor(): void
    {
        [$client, $repository, $service, $logger] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_STALE_EPOCH', ledgerIndex: '45000000')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxErrorResponse('lgrIdxsInvalid'));
        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_AFTER_RESET', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $requests = $client->sentRequests();
        self::assertCount(2, $requests);

        $first = json_decode((string) $requests[0]->getBody(), true);
        self::assertSame(45000001, $first['params'][0]['ledger_index_min']);

        $retry = json_decode((string) $requests[1]->getBody(), true);
        self::assertArrayNotHasKey('ledger_index_min', $retry['params'][0]);

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        self::assertContains('HASH_AFTER_RESET', $hashes);
        self::assertSame('warning', $logger->records()[0]['level']);
    }

    public function testAnRpcErrorThatIsNotAStaleCursorStillPropagates(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_OLD', ledgerIndex: '500')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxErrorResponse('actNotFound'));

        $this->expectException(XrplRpcException::class);

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');
    }

    /**
     * Without a cursor there is nothing to recover *from*: retrying the
     * identical call would fail identically, so the error must surface
     * rather than double every failing sync.
     */
    public function testAStaleCursorErrorWithoutACursorIsNotRetried(): void
    {
        [$client, , $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxErrorResponse('lgrIdxsInvalid'));

        try {
            $service->syncTransactions(self::OWN_ADDRESS, 'testnet');
            self::fail('Expected XrplRpcException.');
        } catch (XrplRpcException) {
            self::assertCount(1, $client->sentRequests());
        }
    }

    public function testSyncedTransactionsRecordTheNetworkTheyCameFrom(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_IN', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        self::assertSame('testnet', $repository->storedTransactions()[0]->network);
    }

    public function testSkipsAMalformedEntryAndLogsAWarningWithoutLosingTheRest(): void
    {
        [$client, $repository, $service, $logger] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            ['tx' => ['Destination' => self::OWN_ADDRESS, 'Account' => 'rSender']], // missing hash, ledger_index, ...
            $this->rawTx(hash: 'HASH_VALID', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        self::assertSame(['HASH_VALID'], $hashes);
        self::assertSame(1, $logger->count());
        self::assertSame('warning', $logger->records()[0]['level']);
    }

    public function testFindTransactionsReturnsEveryCandidateNewestFirst(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(hash: 'HASH_OLD', ledgerIndex: '100', destination: 'rDest', destinationTag: 123),
            $this->hydrated(hash: 'HASH_NEW', ledgerIndex: '900', destination: 'rDest', destinationTag: 123),
            $this->hydrated(hash: 'HASH_OTHER_TAG', ledgerIndex: '950', destination: 'rDest', destinationTag: 999),
        ]);

        $hashes = array_map(
            static fn (XrplTransaction $t): string => $t->hash,
            $service->findTransactions('rDest', 123),
        );

        self::assertSame(['HASH_NEW', 'HASH_OLD'], $hashes);
        self::assertSame([], $service->findTransactions('rDest', 4242));
    }

    /**
     * WooCommerce order #135: an old RLUSD payment sat on the tag an XRP
     * order was quoted against. Taking "the first" candidate handed an
     * issued-currency amount to a native-asset intent, withFulfillment()
     * threw, and the order stayed open forever — with the real XRP payment
     * present and never looked at.
     */
    public function testFindTransactionForSkipsTheOtherAssetClassAndPicksTheRealPayment(): void
    {
        [, $repository, $service, $logger] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(
                hash: 'HASH_STRAY_RLUSD',
                ledgerIndex: '900',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => [
                    'currency' => self::RLUSD_CURRENCY,
                    'value' => '10.00',
                    'issuer' => self::RLUSD_ISSUER,
                ]],
            ),
            $this->hydrated(
                hash: 'HASH_REAL_XRP',
                ledgerIndex: '800',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => '827440'],
            ),
        ]);

        $found = $service->findTransactionFor($this->xrpIntent());

        self::assertSame('HASH_REAL_XRP', $found?->hash);
        self::assertSame('warning', $logger->records()[0]['level']);
    }

    public function testFindTransactionForPrefersTheNewestCandidateOfTheQuotedClass(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(
                hash: 'HASH_EARLIER',
                ledgerIndex: '700',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => '100000'],
            ),
            $this->hydrated(
                hash: 'HASH_LATER',
                ledgerIndex: '800',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => '827440'],
            ),
        ]);

        self::assertSame('HASH_LATER', $service->findTransactionFor($this->xrpIntent())?->hash);
    }

    public function testFindTransactionForReturnsNullWhenOnlyTheWrongAssetClassIsPresent(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(
                hash: 'HASH_STRAY_RLUSD',
                ledgerIndex: '900',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => [
                    'currency' => self::RLUSD_CURRENCY,
                    'value' => '10.00',
                    'issuer' => self::RLUSD_ISSUER,
                ]],
            ),
        ]);

        self::assertNull($service->findTransactionFor($this->xrpIntent()));
    }

    /**
     * A wrong-issuer token is a real payment attempt on this order, not
     * noise: SettlementPolicy is what declares it non-settling, so the
     * platform can tell the customer their token was wrong. Skipping it
     * here would show them nothing at all.
     */
    public function testFindTransactionForKeepsAWrongIssuerCandidateForSettlementPolicy(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(
                hash: 'HASH_WRONG_ISSUER',
                ledgerIndex: '900',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => [
                    'currency' => self::RLUSD_CURRENCY,
                    'value' => '10.00',
                    'issuer' => 'rImpostorIssuerAddress',
                ]],
            ),
        ]);

        self::assertSame('HASH_WRONG_ISSUER', $service->findTransactionFor($this->rlusdIntent())?->hash);
    }

    public function testFindTransactionForSkipsAnUnreadableDeliveredAmountRatherThanAborting(): void
    {
        [, $repository, $service, $logger] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(
                hash: 'HASH_UNAVAILABLE',
                ledgerIndex: '900',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => 'unavailable'],
            ),
            $this->hydrated(
                hash: 'HASH_REAL_XRP',
                ledgerIndex: '800',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => '827440'],
            ),
        ]);

        self::assertSame('HASH_REAL_XRP', $service->findTransactionFor($this->xrpIntent())?->hash);
        self::assertSame('warning', $logger->records()[0]['level']);
    }

    public function testFindTransactionForSkipsATransactionThatDeliveredNothing(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            // An EscrowCreate carries a Destination and lands in the same table.
            $this->hydrated(hash: 'HASH_ESCROW', ledgerIndex: '900', destination: 'rDest', destinationTag: 114729),
            $this->hydrated(
                hash: 'HASH_REAL_XRP',
                ledgerIndex: '800',
                destination: 'rDest',
                destinationTag: 114729,
                meta: ['delivered_amount' => '827440'],
            ),
        ]);

        self::assertSame('HASH_REAL_XRP', $service->findTransactionFor($this->xrpIntent())?->hash);
    }

    /* ---- findFulfillmentFor(): payments in the quoted asset add up ---- */

    public function testFindFulfillmentForAddsUpTwoNativePartialPayments(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->xrpPayment('HASH_FIRST_0_3', ledgerIndex: '800', drops: '300000'),
            $this->xrpPayment('HASH_SECOND_0_7', ledgerIndex: '900', drops: '700000'),
        ]);

        $fulfillment = $service->findFulfillmentFor($this->xrpIntent(1.0));

        self::assertNotNull($fulfillment);
        self::assertSame(1.0, $fulfillment->amountPaid);
        self::assertSame(2, $fulfillment->count());
        self::assertSame('HASH_SECOND_0_7', $fulfillment->hash(), 'the newest contributing transaction');

        $fulfilled = $fulfillment->applyTo($this->xrpIntent(1.0));
        self::assertSame('HASH_SECOND_0_7', $fulfilled->hash);
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($fulfilled, new SettlementPolicy())->state());
    }

    /**
     * The loop the payment page used to send customers into: "0.3 received,
     * send 0.7" — they send 0.7 — "0.7 received, send 0.3". With one
     * transaction judged at a time the order never settled. A top-up of the
     * shortfall has to settle, or the instruction on the page is a lie.
     */
    public function testAToppedUpShortfallSettlesInsteadOfLooping(): void
    {
        [, $repository, $service] = $this->makeService();
        $policy = new SettlementPolicy();
        $intent = $this->xrpIntent(1.0);

        $repository->saveTransactions([$this->xrpPayment('HASH_0_3', ledgerIndex: '800', drops: '300000')]);

        $afterFirst = $service->findFulfillmentFor($intent)?->applyTo($intent);
        $status = PaymentStatus::fromIntent($afterFirst, $policy);
        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertSame(0.7, $status->toArray()['shortfall']);

        // The customer follows the instruction and sends exactly the shortfall.
        $repository->saveTransactions([$this->xrpPayment('HASH_0_7', ledgerIndex: '900', drops: '700000')]);

        $afterTopUp = $service->findFulfillmentFor($intent)?->applyTo($intent);
        self::assertSame(PaymentStatus::SETTLED, PaymentStatus::fromIntent($afterTopUp, $policy)->state());
        self::assertSame('1', $afterTopUp?->amountPaidValue());
    }

    public function testFindFulfillmentForSumsOnlyTheQuotedIssuedCurrencyAndLogsTheForeignOne(): void
    {
        [, $repository, $service, $logger] = $this->makeService();

        $repository->saveTransactions([
            $this->issuedPayment('HASH_RLUSD_4', ledgerIndex: '700', value: '4.00'),
            $this->issuedPayment('HASH_RLUSD_5', ledgerIndex: '800', value: '5.00'),
            // Newest of all, same currency code, another issuer: not this order's money.
            $this->issuedPayment('HASH_IMPOSTOR', ledgerIndex: '900', value: '10.00', issuer: 'rImpostorIssuerAddress'),
        ]);

        $fulfillment = $service->findFulfillmentFor($this->rlusdIntent('10.00'));

        self::assertNotNull($fulfillment);
        self::assertSame(['currency' => self::RLUSD_CURRENCY, 'value' => '9', 'issuer' => self::RLUSD_ISSUER], $fulfillment->amountPaid);
        self::assertSame(2, $fulfillment->count());
        self::assertSame('HASH_RLUSD_5', $fulfillment->hash(), 'the newest *contributing* one, not the newest on the tag');

        $ignored = array_filter($logger->records(), static fn (array $r): bool => str_contains($r['message'], 'another asset on this tag ignored'));
        self::assertCount(1, $ignored);
        self::assertSame('HASH_IMPOSTOR', array_values($ignored)[0]['context']['hash']);
    }

    /**
     * Nothing in the quoted asset, but a payment in another one: that is
     * the fulfillment on its own, so the page can say "wrong token" with
     * the delivered amount — the same outcome findTransactionFor() gave.
     */
    public function testAPaymentInAnotherAssetAloneIsTheFulfillmentSoWrongAssetStaysReachable(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->issuedPayment('HASH_IMPOSTOR', ledgerIndex: '900', value: '10.00', issuer: 'rImpostorIssuerAddress'),
        ]);

        $intent = $this->rlusdIntent('10.00');
        $fulfillment = $service->findFulfillmentFor($intent);

        self::assertNotNull($fulfillment);
        self::assertSame(1, $fulfillment->count());
        self::assertSame('rImpostorIssuerAddress', $fulfillment->amountPaid['issuer'], 'the delivered asset, unchanged');

        $status = PaymentStatus::fromIntent($fulfillment->applyTo($intent), new SettlementPolicy());
        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
        self::assertSame('10', $status->toArray()['shortfall']['value']);
    }

    public function testOnceTheQuotedAssetArrivesOnlyItCountsEvenIfTheWrongOneCameFirst(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->issuedPayment('HASH_IMPOSTOR', ledgerIndex: '700', value: '10.00', issuer: 'rImpostorIssuerAddress'),
            $this->issuedPayment('HASH_RLUSD_3', ledgerIndex: '800', value: '3.00'),
        ]);

        $intent = $this->rlusdIntent('10.00');
        $fulfilled = $service->findFulfillmentFor($intent)?->applyTo($intent);
        $status = PaymentStatus::fromIntent($fulfilled, new SettlementPolicy());

        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertSame('3', $status->toArray()['amount_paid']['value']);
        self::assertSame('7', $status->toArray()['shortfall']['value']);
    }

    /**
     * 0.1 + 0.2 as floats is 0.30000000000000004; amountPaidValue() and
     * shortfall() would carry that noise through to the page.
     */
    public function testNativeAmountsAreSummedExactly(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->xrpPayment('HASH_0_1', ledgerIndex: '800', drops: '100000'),
            $this->xrpPayment('HASH_0_2', ledgerIndex: '900', drops: '200000'),
        ]);

        $intent = $this->xrpIntent(1.0);
        $fulfilled = $service->findFulfillmentFor($intent)?->applyTo($intent);

        self::assertSame(0.3, $fulfilled?->amountPaid);
        self::assertSame('0.3', $fulfilled?->amountPaidValue());
    }

    public function testIssuedAmountsAreSummedAsPlainDecimals(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->issuedPayment('HASH_A', ledgerIndex: '800', value: '0.10'),
            $this->issuedPayment('HASH_B', ledgerIndex: '900', value: '0.2'),
        ]);

        self::assertSame('0.3', $service->findFulfillmentFor($this->rlusdIntent('1.00'))?->amountPaid['value']);
    }

    public function testFindFulfillmentForSkipsWhatFindTransactionForSkippedAndSumsTheRest(): void
    {
        [, $repository, $service, $logger] = $this->makeService();

        $repository->saveTransactions([
            $this->xrpPayment('HASH_0_3', ledgerIndex: '600', drops: '300000'),
            $this->hydrated(hash: 'HASH_ESCROW', ledgerIndex: '700', destination: 'rDest', destinationTag: 114729),
            $this->hydrated(hash: 'HASH_UNAVAILABLE', ledgerIndex: '800', destination: 'rDest', destinationTag: 114729, meta: ['delivered_amount' => 'unavailable']),
            $this->issuedPayment('HASH_OTHER_CLASS', ledgerIndex: '850', value: '10.00'),
            $this->xrpPayment('HASH_0_7', ledgerIndex: '900', drops: '700000'),
        ]);

        $fulfillment = $service->findFulfillmentFor($this->xrpIntent(1.0));

        self::assertSame(1.0, $fulfillment?->amountPaid);
        self::assertSame(['HASH_0_7', 'HASH_0_3'], array_map(static fn (XrplTransaction $t): string => $t->hash, $fulfillment->transactions));
        // Newest first: other class (850), unavailable (800), escrow (700), then the sum.
        self::assertSame(['warning', 'warning', 'debug', 'info'], array_column($logger->records(), 'level'));
    }

    public function testFindFulfillmentForReturnsNullWithoutCandidates(): void
    {
        [, , $service] = $this->makeService();

        self::assertNull($service->findFulfillmentFor($this->xrpIntent()));
    }

    private function xrpPayment(string $hash, string $ledgerIndex, string $drops): XrplTransaction
    {
        return $this->hydrated(
            hash: $hash,
            ledgerIndex: $ledgerIndex,
            destination: 'rDest',
            destinationTag: 114729,
            meta: ['delivered_amount' => $drops],
        );
    }

    private function issuedPayment(string $hash, string $ledgerIndex, string $value, string $issuer = self::RLUSD_ISSUER): XrplTransaction
    {
        return $this->hydrated(
            hash: $hash,
            ledgerIndex: $ledgerIndex,
            destination: 'rDest',
            destinationTag: 114729,
            meta: ['delivered_amount' => ['currency' => self::RLUSD_CURRENCY, 'value' => $value, 'issuer' => $issuer]],
        );
    }

    /**
     * @return array{0: FakeHttpClient, 1: InMemoryXrplTransactionRepository, 2: SyncService, 3: RecordingLogger}
     */
    private function makeService(): array
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $logger = new RecordingLogger();
        $service = new SyncService(
            new XrplClient($client, new HttpFactory(), new HttpFactory()),
            $repository,
            $logger,
        );

        return [$client, $repository, $service, $logger];
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     */
    private function accountTxResponse(array $transactions, string|null $marker = null): Response
    {
        return new Response(200, [], json_encode([
            'result' => [
                'status' => 'success',
                'transactions' => $transactions,
                'marker' => $marker,
            ],
        ]));
    }

    private function accountTxErrorResponse(string $error): Response
    {
        return new Response(200, [], json_encode([
            'result' => [
                'status' => 'error',
                'error' => $error,
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawTx(string $hash, string $account, string $destination, ?int $destinationTag = null): array
    {
        return [
            'tx' => array_filter([
                'ledger_index' => 100,
                'hash' => $hash,
                'ctid' => 'C0000000000000000000000',
                'Account' => $account,
                'Destination' => $destination,
                'DestinationTag' => $destinationTag,
                'date' => 800000000,
            ], static fn ($value) => $value !== null),
            'meta' => [],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function hydrated(
        string $hash,
        string $ledgerIndex,
        string $destination = self::OWN_ADDRESS,
        ?int $destinationTag = null,
        array $meta = [],
        string $network = 'testnet',
    ): XrplTransaction {
        return new XrplTransaction(
            network: $network,
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'C0000000000000000000000',
            account: 'rSender',
            destination: $destination,
            destinationTag: $destinationTag,
            date: 800000000,
            meta: $meta,
            tx: [],
        );
    }

    private function xrpIntent(float $requested = 0.82744): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: $requested,
            destinationAccount: 'rDest',
            destinationTag: 114729,
        );
    }

    private function rlusdIntent(string $requested = '10.00'): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'USD',
            pairing: 'RLUSD/USD',
            exchangeRate: 1.0,
            amountRequested: [
                'currency' => self::RLUSD_CURRENCY,
                'value' => $requested,
                'issuer' => self::RLUSD_ISSUER,
            ],
            destinationAccount: 'rDest',
            destinationTag: 114729,
        );
    }
}
