<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Refund\PaymentOverview;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryRefundRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\ReceivedPayments;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;

final class ReceivedPaymentsTest extends TestCase
{
    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const OTHER_CUSTOMER = 'rB2CxqjRjUkbebPZ6MgnXEaoSUFrmfsC9';
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';
    private const OTHER_ISSUER = 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De';
    private const TAG = 31337;

    private InMemoryXrplTransactionRepository $transactions;
    private InMemoryRefundRepository $refunds;
    private int $refundNumber = 0;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryXrplTransactionRepository();
        $this->refunds = new InMemoryRefundRepository();
    }

    public function testAnExactPaymentCanGoBackInFullForTheOrder(): void
    {
        $this->received('A', '100000000');

        $overview = $this->overview(self::xrpIntent(100.0));
        $payment = $overview->payments[0];

        self::assertSame('100', $overview->received);
        self::assertSame('100', $overview->credited);
        self::assertSame('0', $overview->surplus);
        self::assertSame(PaymentOverview::ORDER_REFUND_NONE, $overview->orderRefundState);
        self::assertFalse($overview->hasOrderRefund());

        self::assertSame(self::hash('A'), $payment->hash);
        self::assertSame(self::CUSTOMER, $payment->sender);
        self::assertTrue($payment->inQuotedAsset);
        self::assertSame('100', $payment->refundable);
        self::assertSame([Refund::PURPOSE_ORDER_REFUND => '100'], $payment->limits);
        self::assertNull($payment->limitFor(Refund::PURPOSE_SURPLUS_RETURN), 'nothing was paid in excess');
        self::assertNull($payment->limitFor(Refund::PURPOSE_WRONG_ASSET_RETURN));
        self::assertSame(848500490 + 946684800, $payment->paidAt);
    }

    /**
     * Two customers' wallets, or one customer topping up: each payment goes back to its own
     * sender, at most with what it delivered itself.
     */
    public function testEveryPaymentHasItsOwnSenderAndItsOwnLimit(): void
    {
        $this->received('A', '60000000', ledgerIndex: 100);
        $this->received('B', '40000000', sender: self::OTHER_CUSTOMER, ledgerIndex: 200);

        $overview = $this->overview(self::xrpIntent(100.0));

        self::assertSame('100', $overview->received);
        self::assertSame([self::hash('B'), self::hash('A')], array_column($overview->payments, 'hash'), 'newest first');
        self::assertSame('40', $overview->find(self::hash('B'))?->refundable);
        self::assertSame(self::OTHER_CUSTOMER, $overview->find(self::hash('B'))?->sender);
        self::assertSame('60', $overview->find(strtolower(self::hash('A')))?->refundable);
        self::assertNull($overview->find(self::hash('nothing')));
    }

    public function testSumsAreExact(): void
    {
        // 0.1 + 0.2 as floats is 0.30000000000000004.
        $this->received('A', '100000', ledgerIndex: 100);
        $this->received('B', '200000', ledgerIndex: 200);

        $overview = $this->overview(self::xrpIntent(0.3));

        self::assertSame('0.3', $overview->received);
        self::assertSame('0', $overview->surplus);
    }

    /**
     * 110 for an order of 100: the excess of 10 can go back without touching the order.
     */
    public function testWhatWasPaidInExcessCanGoBackAsASurplusReturnAndNoMore(): void
    {
        $this->received('A', '110000000');
        $intent = self::xrpIntent(100.0);

        $overview = $this->overview($intent);
        self::assertSame('110', $overview->received);
        self::assertSame('100', $overview->credited);
        self::assertSame('10', $overview->surplus);
        self::assertSame('10', $overview->surplusReturnable);
        self::assertSame(
            [Refund::PURPOSE_ORDER_REFUND => '110', Refund::PURPOSE_SURPLUS_RETURN => '10'],
            $overview->payments[0]->limits,
        );

        $this->refund('A', '4', Refund::PURPOSE_SURPLUS_RETURN);

        $overview = $this->overview($intent);
        self::assertSame('6', $overview->surplusReturnable, 'an open request already claims its part of the excess');
        self::assertSame('4', $overview->payments[0]->pending);
        self::assertSame('0', $overview->payments[0]->refunded);
        self::assertSame('106', $overview->payments[0]->refundable);
        self::assertSame(
            [Refund::PURPOSE_ORDER_REFUND => '106', Refund::PURPOSE_SURPLUS_RETURN => '6'],
            $overview->payments[0]->limits,
        );
        self::assertFalse($overview->hasOrderRefund(), 'a surplus return is not a refund of the order');
        self::assertSame(PaymentOverview::ORDER_REFUND_NONE, $overview->orderRefundState);
    }

    public function testTheSurplusLimitOfAPaymentIsNeverMoreThanThePaymentStillHas(): void
    {
        $this->received('A', '100000000', ledgerIndex: 100);
        $this->received('B', '30000000', ledgerIndex: 200);
        // Everything but 2 of the second payment is already on its way back for the order.
        $this->refund('B', '28');

        $overview = $this->overview(self::xrpIntent(100.0));

        self::assertSame('30', $overview->surplus);
        self::assertSame('2', $overview->find(self::hash('B'))?->limitFor(Refund::PURPOSE_SURPLUS_RETURN));
        self::assertSame('30', $overview->find(self::hash('A'))?->limitFor(Refund::PURPOSE_SURPLUS_RETURN));
    }

    /**
     * What arrived counts, not what was asked: a refund executed with 59.5 instead of 60 leaves
     * 0.5 more to give back; a cancelled one claims nothing.
     */
    public function testExecutedRefundsCountWithWhatTheyDeliveredAndCancelledOnesNotAtAll(): void
    {
        $this->received('A', '100000000');
        $executed = $this->refund('A', '60');
        $this->refunds->update(
            $executed->executed(self::hash('execution'), 300, 1_800_000_100, self::MERCHANT, LedgerAmount::native('59.5'), Refund::MATCHED_BY_ATTACHED, 1_800_000_200),
            $executed->version,
        );
        $cancelled = $this->refund('A', '10');
        $this->refunds->update($cancelled->cancelled(1_800_000_300), $cancelled->version);
        $this->refund('A', '5');

        $payment = $this->overview(self::xrpIntent(100.0))->payments[0];

        self::assertSame('59.5', $payment->refunded);
        self::assertSame('5', $payment->pending);
        self::assertSame('35.5', $payment->refundable);
        self::assertCount(3, $payment->refunds);
        self::assertSame([1, 2, 3], array_map(static fn (Refund $refund): int => $refund->serial, $payment->refunds));
    }

    public function testAPaymentThatHasNothingLeftOffersNothing(): void
    {
        $this->received('A', '100000000');
        $this->refund('A', '100');

        $payment = $this->overview(self::xrpIntent(100.0))->payments[0];

        self::assertSame('0', $payment->refundable);
        self::assertSame([], $payment->limits);
    }

    /**
     * RLUSD on an XRP order: it is shown, it counts for nothing, and it can only go back as it came.
     */
    public function testAPaymentInAnotherAssetCanOnlyBeReturned(): void
    {
        $this->received('A', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '5']);

        $overview = $this->overview(self::xrpIntent(100.0));
        $payment = $overview->payments[0];

        self::assertSame('0', $overview->received);
        self::assertFalse($payment->inQuotedAsset);
        self::assertSame(self::RLUSD, $payment->delivered->currency);
        self::assertSame([Refund::PURPOSE_WRONG_ASSET_RETURN => '5'], $payment->limits);
    }

    public function testTheSameCurrencyFromAnotherIssuerIsAnotherAsset(): void
    {
        $this->received('A', ['currency' => self::RLUSD, 'issuer' => self::OTHER_ISSUER, 'value' => '86.40'], ledgerIndex: 100);
        $this->received('B', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '86.40'], ledgerIndex: 200);

        $overview = $this->overview(self::rlusdIntent('86.40'));

        self::assertSame('86.4', $overview->received);
        self::assertSame('0', $overview->surplus);
        self::assertSame([Refund::PURPOSE_WRONG_ASSET_RETURN => '86.4'], $overview->find(self::hash('A'))?->limits);
        self::assertSame([Refund::PURPOSE_ORDER_REFUND => '86.4'], $overview->find(self::hash('B'))?->limits);
    }

    /**
     * The same key pair is an account on testnet and on mainnet, with the same tag.
     */
    public function testAPaymentOnAnotherNetworkIsNotTheOrders(): void
    {
        $this->received('A', '100000000', network: 'mainnet');

        $overview = $this->overview(self::xrpIntent(100.0));

        self::assertSame([], $overview->payments);
        self::assertSame('0', $overview->received);
    }

    public function testWhatDeliveredNothingIsNotAPayment(): void
    {
        $this->store('A', meta: ['TransactionResult' => 'tesSUCCESS']);
        $this->store('B', meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => 'unavailable']);
        $this->store('C', meta: ['TransactionResult' => 'tecPATH_DRY', 'delivered_amount' => '100000000']);
        $this->store('D', meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => ['mpt_issuance_id' => '00', 'value' => '1']]);

        self::assertSame([], $this->overview(self::xrpIntent(100.0))->payments);
    }

    public function testAPaymentOnAnotherTagIsNotTheOrders(): void
    {
        $this->received('A', '100000000', tag: self::TAG + 1);

        self::assertSame([], $this->overview(self::xrpIntent(100.0))->payments);
    }

    /**
     * Whether the order is refunded in part or in full is decided in tokens, against what
     * counted for the order, and only by refunds that were executed.
     */
    public function testTheOrderIsRefundedInPartOrInFullByExecutedOrderRefundsOnly(): void
    {
        $this->received('A', '100000000');
        $intent = self::xrpIntent(100.0);

        $first = $this->refund('A', '40');
        $overview = $this->overview($intent);
        self::assertTrue($overview->hasOrderRefund());
        self::assertSame(PaymentOverview::ORDER_REFUND_NONE, $overview->orderRefundState, 'an open request has returned nothing yet');

        $this->execute($first, 'execution-1');
        $overview = $this->overview($intent);
        self::assertSame('40', $overview->orderRefunded);
        self::assertSame(PaymentOverview::ORDER_REFUND_PARTIAL, $overview->orderRefundState);

        $this->execute($this->refund('A', '60'), 'execution-2');
        $overview = $this->overview($intent);
        self::assertSame('100', $overview->orderRefunded);
        self::assertSame(PaymentOverview::ORDER_REFUND_FULL, $overview->orderRefundState);
    }

    /**
     * 99.9 arrived for 100: all of what arrived going back is the whole order going back.
     */
    public function testAShortPaymentGoingBackCompletelyIsAFullRefund(): void
    {
        $this->received('A', '99900000');
        $this->execute($this->refund('A', '99.9'), 'execution-1');

        $overview = $this->overview(self::xrpIntent(100.0));

        self::assertSame('99.9', $overview->credited);
        self::assertSame(PaymentOverview::ORDER_REFUND_FULL, $overview->orderRefundState);
    }

    public function testASurplusReturnDoesNotRefundTheOrder(): void
    {
        $this->received('A', '110000000');
        $this->execute($this->refund('A', '10', Refund::PURPOSE_SURPLUS_RETURN), 'execution-1');

        $overview = $this->overview(self::xrpIntent(100.0));

        self::assertSame('0', $overview->orderRefunded);
        self::assertSame(PaymentOverview::ORDER_REFUND_NONE, $overview->orderRefundState);
        self::assertSame('0', $overview->surplusReturnable);
        self::assertSame([Refund::PURPOSE_ORDER_REFUND => '100'], $overview->payments[0]->limits);
    }

    public function testACancelledOrderRefundIsNoOrderRefund(): void
    {
        $this->received('A', '100000000');
        $refund = $this->refund('A', '40');
        $this->refunds->update($refund->cancelled(1_800_000_300), $refund->version);

        self::assertFalse($this->overview(self::xrpIntent(100.0))->hasOrderRefund());
    }

    public function testTheOverviewAsAnArray(): void
    {
        $this->received('A', '110000000');
        $this->refund('A', '4', Refund::PURPOSE_SURPLUS_RETURN);

        $array = $this->overview(self::xrpIntent(100.0))->toArray();

        self::assertSame(
            ['requested', 'received', 'credited', 'surplus', 'surplus_returnable', 'order_refunded', 'order_refund_state', 'payments'],
            array_keys($array),
        );
        self::assertSame(['value' => '100', 'currency' => null, 'issuer' => null], $array['requested']);
        self::assertSame(
            ['hash', 'ledger_index', 'paid_at', 'sender', 'delivered', 'in_quoted_asset', 'refunded', 'pending', 'refundable', 'limits', 'refunds'],
            array_keys($array['payments'][0]),
        );
        self::assertSame('surplus_return', $array['payments'][0]['refunds'][0]['purpose']);
    }

    private function overview(PaymentIntent $intent): PaymentOverview
    {
        return (new ReceivedPayments($this->transactions, $this->refunds, new RecordingLogger()))->of($intent);
    }

    /**
     * @param string|array<string, string> $delivered as the ledger writes it: drops, or a token amount
     */
    private function received(
        string $name,
        string|array $delivered,
        string $sender = self::CUSTOMER,
        int $ledgerIndex = 100,
        string $network = 'testnet',
        int $tag = self::TAG,
    ): void {
        $this->store($name, ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => $delivered], $sender, $ledgerIndex, $network, $tag);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function store(
        string $name,
        array $meta,
        string $sender = self::CUSTOMER,
        int $ledgerIndex = 100,
        string $network = 'testnet',
        int $tag = self::TAG,
    ): void {
        $this->transactions->saveTransactions([new XrplTransaction(
            network: $network,
            ledgerIndex: (string) $ledgerIndex,
            hash: self::hash($name),
            ctid: 'C000006400000001',
            account: $sender,
            destination: self::MERCHANT,
            destinationTag: $tag,
            date: 848500490,
            meta: $meta,
            tx: [],
        )]);
    }

    /** An open refund of the named payment, with the next serial. */
    private function refund(string $payment, string $amount, string $purpose = Refund::PURPOSE_ORDER_REFUND): Refund
    {
        $number = ++$this->refundNumber;
        $serial = count($this->refunds->findBySource('testnet', self::hash($payment))) + 1;

        $refund = Refund::request(
            id: str_pad(dechex($number), 32, '0', STR_PAD_LEFT),
            chain: 'XRPL',
            network: 'testnet',
            purpose: $purpose,
            platformReference: 'order-transaction-1',
            sourceHash: self::hash($payment),
            serial: $serial,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(self::CUSTOMER, 4711, RefundDestination::ORIGIN_STATED),
            amount: LedgerAmount::native($amount),
            reference: self::hash('reference-' . $number),
            createdAt: 1_800_000_000 + $number,
            createdLedger: 250,
            createdBy: 'admin',
        );
        self::assertTrue($this->refunds->insert($refund));

        return $refund;
    }

    private function execute(Refund $refund, string $hash): void
    {
        self::assertTrue($this->refunds->update(
            $refund->executed(self::hash($hash), 300, 1_800_000_100, self::MERCHANT, $refund->amount, Refund::MATCHED_BY_KEY, 1_800_000_200),
            $refund->version,
        ));
    }

    private static function xrpIntent(float $amount): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.2,
            amountRequested: $amount,
            destinationAccount: self::MERCHANT,
            destinationTag: self::TAG,
        );
    }

    private static function rlusdIntent(string $amount): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'EUR',
            pairing: 'RLUSD/EUR',
            exchangeRate: 0.92592592592593,
            amountRequested: ['currency' => self::RLUSD, 'value' => $amount, 'issuer' => self::ISSUER],
            destinationAccount: self::MERCHANT,
            destinationTag: self::TAG,
        );
    }

    private static function hash(string $name): string
    {
        return strtoupper(hash('sha256', $name));
    }
}
