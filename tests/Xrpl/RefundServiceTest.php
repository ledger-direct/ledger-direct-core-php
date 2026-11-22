<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundNotice;
use Hardcastle\LedgerDirect\Core\Refund\RefundPreview;
use Hardcastle\LedgerDirect\Core\Refund\RefundRefusedException;
use Hardcastle\LedgerDirect\Core\Refund\RefundRequest;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryRefundRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules a refund request has to pass (INVARIANTS.md, "Refund"), one test per rule. The node
 * answers with what was recorded on the XRPL testnet on 2026-11-20: the customer's account
 * before and after it set RequireDest, its trust lines, and a payment as `tx` returns it.
 */
final class RefundServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/refund/';

    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    /** An address the customer names instead of the one they paid from. */
    private const NAMED = 'rB2CxqjRjUkbebPZ6MgnXEaoSUFrmfsC9';
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';
    private const TAG = 31337;
    private const NOW = 1_800_000_000;
    /** `validated_ledger.seq` of the recorded server_state. */
    private const VALIDATED_LEDGER = 22396432;

    private InMemoryXrplTransactionRepository $transactions;
    private InMemoryRefundRepository $refunds;
    private FakeNode $node;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryXrplTransactionRepository();
        $this->refunds = new InMemoryRefundRepository();
        $this->clock = new FrozenClock(self::NOW);

        $named = self::recorded('account-info-customer.json');
        $named['account_data']['Account'] = self::NAMED;

        $this->node = (new FakeNode())
            ->answer('account_info ' . self::CUSTOMER, self::recorded('account-info-customer.json'))
            ->answer('account_lines ' . self::CUSTOMER, self::recorded('account-lines-customer-without-trustline.json'))
            ->answer('account_info ' . self::NAMED, $named)
            ->answer('account_lines ' . self::NAMED, self::recorded('account-lines-customer-without-trustline.json'))
            ->answer('account_info ' . self::ISSUER, 'rlusd-issuer-account-info.json')
            ->answer('server_state', 'server-state.json')
            // The paying account has sent nothing so far.
            ->answer('account_tx', ['status' => 'success', 'ledger_index_max' => self::VALIDATED_LEDGER, 'transactions' => []]);
    }

    /* ---------- back to the sender ---------- */

    /**
     * Nothing speaks against the sender, and still nothing goes out without a tag until
     * somebody says the address is the customer's own wallet.
     */
    public function testARefundToTheSenderWithoutATagNeedsTheMerchantsConfirmation(): void
    {
        $this->paid('A', '100000000');

        $preview = $this->service()->preview($this->request(amount: '40'));

        self::assertFalse($preview->isAllowed());
        self::assertSame([RefundNotice::CONFIRMATION_REQUIRED], self::codes($preview->blockers()));
        self::assertTrue($preview->needsOwnWalletConfirmation);
        self::assertSame(self::CUSTOMER, $preview->destination);
        self::assertNull($preview->destinationTag);
        self::assertSame(RefundDestination::ORIGIN_SENDER, $preview->destinationOrigin);
        self::assertSame(self::MERCHANT, $preview->payingAccount);
        self::assertSame('40', $preview->amount?->value);
        self::assertSame('100', $preview->limit);
        self::assertSame([], $this->refunds->storedRows(), 'a preview stores nothing');

        $this->assertRefused(RefundNotice::CONFIRMATION_REQUIRED, $this->request(amount: '40'));
    }

    public function testAConfirmedRefundToTheSenderIsCreatedAwaitingExecution(): void
    {
        $this->paid('A', '100000000');
        $request = $this->request(amount: '40', ownWalletConfirmed: true, reason: '  Goods returned  ', createdBy: 'admin');

        self::assertTrue($this->service()->preview($request)->isAllowed());

        $refund = $this->service()->create($request);

        self::assertTrue($refund->isAwaitingExecution());
        self::assertSame('XRPL', $refund->chain);
        self::assertSame('testnet', $refund->network);
        self::assertSame(Refund::PURPOSE_ORDER_REFUND, $refund->purpose);
        self::assertSame('order-transaction-1', $refund->platformReference);
        self::assertSame(self::hash('A'), $refund->sourceHash);
        self::assertSame(1, $refund->serial);
        self::assertSame(self::MERCHANT, $refund->payingAccount, 'the account the payment went to pays it back');
        self::assertSame(self::CUSTOMER, $refund->destination->account);
        self::assertNull($refund->destination->tag);
        self::assertSame(RefundDestination::ORIGIN_SENDER, $refund->destination->origin);
        self::assertSame(RefundDestination::ATTESTATION_OWN_WALLET, $refund->destination->attestation);
        self::assertTrue($refund->amount->isNative());
        self::assertSame('40', $refund->amount->value);
        self::assertSame(self::NOW, $refund->createdAt);
        self::assertSame(self::VALIDATED_LEDGER, $refund->createdLedger);
        self::assertSame('admin', $refund->createdBy);
        self::assertSame('Goods returned', $refund->reason);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $refund->id);
        self::assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $refund->reference);

        self::assertEquals($refund, $this->refunds->find($refund->id));
        self::assertEquals([$refund], $this->service()->findByPlatformReference('order-transaction-1'));
    }

    /**
     * An account that requires a tag is an account many customers share. Confirming does not help.
     */
    public function testASenderThatRequiresATagIsNeverPaidBackWithoutOne(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('account_info ' . self::CUSTOMER, self::recorded('account-info-customer-requires-tag.json'));

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::SENDER_MAY_BE_SHARED], self::codes($preview->blockers()));
        self::assertSame(
            ['sender' => self::CUSTOMER, 'reason' => 'requires_tag'],
            $preview->notice(RefundNotice::SENDER_MAY_BE_SHARED)?->context,
        );
        self::assertFalse($preview->needsOwnWalletConfirmation, 'there is nothing to confirm here');

        $this->assertRefused(RefundNotice::SENDER_MAY_BE_SHARED, $this->request(amount: '40', ownWalletConfirmed: true));
    }

    /**
     * A source tag is how a shared account says whose payment it was. The tag is offered as a
     * question for the customer, and never used by the core itself.
     */
    public function testASenderWhosePaymentCarriedASourceTagIsNeverPaidBackWithoutATag(): void
    {
        $this->paid('A', '100000000', sourceTag: 778899);

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::SENDER_MAY_BE_SHARED], self::codes($preview->blockers()));
        self::assertSame(
            ['sender' => self::CUSTOMER, 'reason' => 'source_tag', 'source_tag' => '778899'],
            $preview->notice(RefundNotice::SENDER_MAY_BE_SHARED)?->context,
        );
        self::assertSame(778899, $preview->sourceTag);
        self::assertNull($preview->destinationTag, 'the source tag is a suggestion, not the refund\'s tag');

        $this->assertRefused(RefundNotice::SENDER_MAY_BE_SHARED, $this->request(amount: '40', ownWalletConfirmed: true));
    }

    /**
     * If the ledger no longer has the payment, nothing is known about its source tag: that is
     * not the same as "it had none".
     */
    public function testASenderWhosePaymentCannotBeReadIsNotPaidBackWithoutATag(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('tx ' . self::hash('A'), ['status' => 'error', 'error' => 'txnNotFound']);

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::SENDER_MAY_BE_SHARED], self::codes($preview->blockers()));
        self::assertSame('source_unreadable', $preview->notice(RefundNotice::SENDER_MAY_BE_SHARED)?->context['reason']);
    }

    /**
     * The account an exchange pays out from is often not the one it takes deposits on.
     */
    public function testTheSendersAddressIsNeverCombinedWithATypedTag(): void
    {
        $this->paid('A', '100000000');

        $preview = $this->service()->preview($this->request(amount: '40', destinationTag: '4711', destinationTagRepeat: '4711'));

        self::assertTrue($preview->has(RefundNotice::TAG_INVALID));
        self::assertSame('tag_without_destination', $preview->notice(RefundNotice::TAG_INVALID)?->context['reason']);
        self::assertNull($preview->destinationTag);
    }

    /* ---------- to an address the customer named ---------- */

    public function testANamedAddressWithItsTagIsCreatedWithoutAnyConfirmation(): void
    {
        $this->paid('A', '100000000', sourceTag: 778899);

        $request = $this->request(amount: '40', destination: self::NAMED, destinationTag: '4711', destinationTagRepeat: '4711');
        $preview = $this->service()->preview($request);

        self::assertTrue($preview->isAllowed());
        self::assertSame(4711, $preview->destinationTag);
        self::assertTrue($preview->has(RefundNotice::CHECK_TAG_IN_WALLET), 'this account does not enforce the tag');
        self::assertFalse($preview->notice(RefundNotice::CHECK_TAG_IN_WALLET)?->blocks);

        $refund = $this->service()->create($request);

        self::assertSame(self::NAMED, $refund->destination->account);
        self::assertSame(4711, $refund->destination->tag);
        self::assertSame(RefundDestination::ORIGIN_STATED, $refund->destination->origin);
        self::assertNull($refund->destination->attestation);
        self::assertStringContainsString('dt=4711', (string) $this->service()->paymentUri($refund));
    }

    public function testAnAccountThatEnforcesTheTagNeedsNoReminderToCheckIt(): void
    {
        $this->paid('A', '100000000');
        $requiresTag = self::recorded('account-info-customer-requires-tag.json');
        $requiresTag['account_data']['Account'] = self::NAMED;
        $this->node->answer('account_info ' . self::NAMED, $requiresTag);

        $preview = $this->service()->preview($this->request(amount: '40', destination: self::NAMED, destinationTag: '4711', destinationTagRepeat: '4711'));

        self::assertTrue($preview->isAllowed());
        self::assertSame([], $preview->notices);
    }

    /**
     * An address carries a checksum, a tag does not: a transposed digit credits a stranger.
     */
    #[DataProvider('tagsThatDoNotPass')]
    public function testATagIsTypedTwiceAndMustBeATag(string $tag, string $repeat, string $reason): void
    {
        $this->paid('A', '100000000');

        $preview = $this->service()->preview($this->request(amount: '40', destination: self::NAMED, destinationTag: $tag, destinationTagRepeat: $repeat));

        self::assertFalse($preview->isAllowed());
        self::assertSame($reason, $preview->notice(RefundNotice::TAG_INVALID)?->context['reason']);
        self::assertNull($preview->destinationTag);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function tagsThatDoNotPass(): array
    {
        return [
            'two digits transposed' => ['4711', '4171', 'repetition_differs'],
            'not repeated' => ['4711', '', 'repetition_differs'],
            'only the repetition' => ['', '4711', 'not_a_tag'],
            'not a number' => ['47a1', '47a1', 'not_a_tag'],
            'negative' => ['-1', '-1', 'not_a_tag'],
            'beyond 32 bits' => ['4294967296', '4294967296', 'not_a_tag'],
            'a fraction' => ['47.11', '47.11', 'not_a_tag'],
        ];
    }

    public function testTheWholeTagRangeIsAccepted(): void
    {
        $this->paid('A', '100000000');

        foreach (['0' => 0, '4294967295' => 4294967295, ' 4711 ' => 4711] as $typed => $tag) {
            $preview = $this->service()->preview($this->request(amount: '40', destination: self::NAMED, destinationTag: (string) $typed, destinationTagRepeat: (string) $typed));

            self::assertTrue($preview->isAllowed(), "tag '{$typed}'");
            self::assertSame($tag, $preview->destinationTag);
        }
    }

    /**
     * The customer paid from an exchange and names their own wallet: no tag there, and the
     * merchant vouches for it.
     */
    public function testANamedAddressWithoutATagNeedsTheSameConfirmation(): void
    {
        $this->paid('A', '100000000', sourceTag: 778899);

        $this->assertRefused(RefundNotice::CONFIRMATION_REQUIRED, $this->request(amount: '40', destination: self::NAMED));

        $refund = $this->service()->create($this->request(amount: '40', destination: self::NAMED, ownWalletConfirmed: true));

        self::assertSame(self::NAMED, $refund->destination->account);
        self::assertNull($refund->destination->tag);
        self::assertSame(RefundDestination::ORIGIN_STATED, $refund->destination->origin);
        self::assertSame(RefundDestination::ATTESTATION_OWN_WALLET, $refund->destination->attestation);
        self::assertStringNotContainsString('dt=', (string) $this->service()->paymentUri($refund));
    }

    public function testANamedAddressThatRequiresATagGetsNoneWaived(): void
    {
        $this->paid('A', '100000000');
        $requiresTag = self::recorded('account-info-customer-requires-tag.json');
        $requiresTag['account_data']['Account'] = self::NAMED;
        $this->node->answer('account_info ' . self::NAMED, $requiresTag);

        $preview = $this->service()->preview($this->request(amount: '40', destination: self::NAMED, ownWalletConfirmed: true));

        self::assertSame([RefundNotice::TAG_REQUIRED], self::codes($preview->blockers()));
        self::assertFalse($preview->needsOwnWalletConfirmation);
    }

    /**
     * Typing the sender's address in is not a way around the rule for the sender: with a source
     * tag on the payment it stays refused without a tag, and says why.
     */
    public function testTheRuleForTheSenderHoldsWhenTheSendersAddressIsTypedIn(): void
    {
        $this->paid('A', '100000000', sourceTag: 778899);

        $preview = $this->service()->preview($this->request(amount: '40', destination: self::CUSTOMER, ownWalletConfirmed: true));

        self::assertSame([RefundNotice::SENDER_MAY_BE_SHARED], self::codes($preview->blockers()));
        self::assertSame(
            ['sender' => self::CUSTOMER, 'reason' => 'source_tag', 'source_tag' => '778899'],
            $preview->notice(RefundNotice::SENDER_MAY_BE_SHARED)?->context,
        );
        self::assertSame(RefundDestination::ORIGIN_STATED, $preview->destinationOrigin);

        // The way out: the customer confirms the tag.
        $refund = $this->service()->create($this->request(amount: '40', destination: self::CUSTOMER, destinationTag: '778899', destinationTagRepeat: '778899'));

        self::assertSame(self::CUSTOMER, $refund->destination->account);
        self::assertSame(778899, $refund->destination->tag);
    }

    public function testAnAddressWithAWrongChecksumIsNotADestination(): void
    {
        $this->paid('A', '100000000');
        $mistyped = substr(self::NAMED, 0, -1) . '8';

        $preview = $this->service()->preview($this->request(amount: '40', destination: $mistyped, destinationTag: '1', destinationTagRepeat: '1'));

        self::assertSame([RefundNotice::DESTINATION_INVALID], self::codes($preview->blockers()));
        self::assertNull($preview->destination);
        self::assertSame(0, $this->node->count('account_info'), 'the ledger is not asked about an address that is not one');
    }

    public function testARefundDoesNotGoToThePayingAccountOrToTheIssuer(): void
    {
        $this->paid('A', '100000000');
        $this->paid('B', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '5'], ledgerIndex: 22475012);

        $toItself = $this->service()->preview($this->request(amount: '40', destination: self::MERCHANT, ownWalletConfirmed: true));
        self::assertSame([RefundNotice::DESTINATION_IS_PAYING_ACCOUNT], self::codes($toItself->blockers()));

        $toIssuer = $this->service()->preview($this->request(
            payment: 'B',
            purpose: Refund::PURPOSE_WRONG_ASSET_RETURN,
            amount: '5',
            destination: self::ISSUER,
            ownWalletConfirmed: true,
        ));
        self::assertSame([RefundNotice::DESTINATION_IS_ISSUER], self::codes($toIssuer->blockers()));
    }

    /**
     * Whoever enters a destination can enter their own, and whoever confirms "own wallet"
     * vouches for it. A request that does not say who makes it is not looked at.
     */
    public function testARefundRequestNamesWhoMakesIt(): void
    {
        $this->paid('A', '100000000');

        foreach ([null, '', '   '] as $nobody) {
            try {
                $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true, createdBy: $nobody));
                self::fail('A request without a creator must not be previewed.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('createdBy', $exception->getMessage());
            }
        }

        $refund = $this->service()->create($this->request(amount: '40', ownWalletConfirmed: true, createdBy: '  Zoë  '));
        self::assertSame('Zoë', $refund->createdBy);
    }

    /* ---------- the amount ---------- */

    public function testARefundNeverExceedsWhatThePaymentStillHas(): void
    {
        $this->paid('A', '100000000');
        $this->service()->create($this->request(amount: '60', ownWalletConfirmed: true));

        $preview = $this->service()->preview($this->request(amount: '40.000001', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE], self::codes($preview->blockers()));
        self::assertSame(['refundable' => '40'], $preview->notice(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE)?->context);

        $refund = $this->service()->create($this->request(amount: '40', ownWalletConfirmed: true));
        self::assertSame(2, $refund->serial);

        $nothingLeft = $this->service()->preview($this->request(amount: '0.000001', ownWalletConfirmed: true));
        self::assertSame(['refundable' => '0'], $nothingLeft->notice(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE)?->context);
    }

    public function testEverythingIsExactlyWhatIsLeft(): void
    {
        $this->paid('A', '100000001');
        $this->service()->create($this->request(amount: '60', ownWalletConfirmed: true));

        $refund = $this->service()->create($this->request(everything: true, ownWalletConfirmed: true));

        self::assertSame('40.000001', $refund->amount->value);
    }

    /**
     * 80.00 paid as 86.40 RLUSD; 20.00 back is a quarter of the tokens.
     */
    public function testAnAmountInTheShopsCurrencyIsTheSameShareOfTheTokens(): void
    {
        $this->paidRlusd('A', '86.40');
        $this->trustline(self::CUSTOMER);
        $intent = self::rlusdIntent();

        $refund = $this->service()->create($this->request($intent, fiatAmount: '20.00', orderValue: '80.00', ownWalletConfirmed: true));

        self::assertSame('21.6', $refund->amount->value);
        self::assertSame(self::RLUSD, $refund->amount->currency);
        self::assertSame(self::ISSUER, $refund->amount->issuer);
        self::assertSame('20', $refund->fiatAmount);
        self::assertSame('EUR', $refund->fiatCurrency);
    }

    /**
     * The platform says what the order is worth; if that is not what the quote was made for
     * (the order was changed since), the tokens have to be entered directly.
     */
    public function testAnOrderValueThatIsNotTheQuotesRefusesTheShopCurrencyAmount(): void
    {
        $this->paidRlusd('A', '86.40');
        $this->trustline(self::CUSTOMER);
        $intent = self::rlusdIntent();

        foreach (['75.00', null] as $orderValue) {
            $preview = $this->service()->preview($this->request($intent, fiatAmount: '20.00', orderValue: $orderValue, ownWalletConfirmed: true));

            self::assertSame([RefundNotice::FIAT_BASIS_MISMATCH], self::codes($preview->blockers()));
            self::assertNull($preview->amount);
        }

        self::assertTrue($this->service()->preview($this->request($intent, amount: '21.6', ownWalletConfirmed: true))->isAllowed());
    }

    /**
     * 100.00 quoted as 45.45455 XRP, paid back as 33.33 and 66.67. Each share is rounded up:
     * 15.15001 and 30.30455, one step more than was paid. For the second, only 30.30454 is
     * left: the rounding is over the limit, the share is not, and the limit wins.
     */
    public function testRoundingUpNeverPushesTheLastRefundOverWhatIsLeft(): void
    {
        $this->paid('A', '45454550');
        $intent = self::xrpIntent(45.45455);

        $first = $this->service()->create($this->request($intent, fiatAmount: '33.33', orderValue: '100.00', ownWalletConfirmed: true));
        self::assertSame('15.15001', $first->amount->value);

        $tooMuch = $this->service()->preview($this->request($intent, fiatAmount: '66.68', orderValue: '100.00', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE], self::codes($tooMuch->blockers()), 'a cent more is a real excess, not rounding');

        $last = $this->service()->create($this->request($intent, fiatAmount: '66.67', orderValue: '100.00', ownWalletConfirmed: true));
        self::assertSame('30.30454', $last->amount->value);
        self::assertSame('66.67', $last->fiatAmount);

        self::assertSame('0', $this->service()->overview($intent)->payments[0]->refundable);
    }

    /**
     * @param array<string, mixed> $amountArguments
     */
    #[DataProvider('amountsThatAreNone')]
    public function testWhatIsNotOneAmountIsRefused(array $amountArguments): void
    {
        $this->paid('A', '100000000');

        $preview = $this->service()->preview($this->request(...$amountArguments, ownWalletConfirmed: true));

        self::assertSame([RefundNotice::AMOUNT_INVALID], self::codes($preview->blockers()));
        self::assertNull($preview->amount);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function amountsThatAreNone(): array
    {
        return [
            'no amount at all' => [[]],
            'two ways at once' => [['amount' => '40', 'everything' => true]],
            'tokens and shop currency' => [['amount' => '40', 'fiatAmount' => '20', 'orderValue' => '220']],
            'zero' => [['amount' => '0']],
            'negative' => [['amount' => '-40']],
            'not a number' => [['amount' => 'forty']],
            'finer than a drop' => [['amount' => '1.0000001']],
            'a shop currency amount of nothing' => [['fiatAmount' => '0', 'orderValue' => '220.00']],
        ];
    }

    public function testATokenAmountTheLedgerCannotHoldExactlyIsRefused(): void
    {
        $this->paidRlusd('A', '86.40');
        $this->trustline(self::CUSTOMER);
        $intent = self::rlusdIntent();

        self::assertTrue($this->service()->preview($this->request($intent, amount: '1.234567890123456', ownWalletConfirmed: true))->isAllowed());
        self::assertSame(
            [RefundNotice::AMOUNT_INVALID],
            self::codes($this->service()->preview($this->request($intent, amount: '1.2345678901234567', ownWalletConfirmed: true))->blockers()),
        );
    }

    /**
     * What the payment is decides what it can be paid back as.
     */
    public function testThePurposeHasToFitThePayment(): void
    {
        $this->paid('A', '100000000');
        $this->paid('B', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '5'], ledgerIndex: 22475012);
        $this->trustline(self::CUSTOMER);

        $refundOfTheWrongAsset = $this->service()->preview($this->request(payment: 'B', amount: '5', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::PURPOSE_NOT_ALLOWED], self::codes($refundOfTheWrongAsset->blockers()));

        $returnOfTheRightAsset = $this->service()->preview($this->request(purpose: Refund::PURPOSE_WRONG_ASSET_RETURN, amount: '5', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::PURPOSE_NOT_ALLOWED], self::codes($returnOfTheRightAsset->blockers()));

        $refund = $this->service()->create($this->request(payment: 'B', purpose: Refund::PURPOSE_WRONG_ASSET_RETURN, everything: true, ownWalletConfirmed: true));
        self::assertSame(Refund::PURPOSE_WRONG_ASSET_RETURN, $refund->purpose);
        self::assertSame('5', $refund->amount->value);
        self::assertSame(self::RLUSD, $refund->amount->currency);
    }

    /**
     * The quote's rate is for the quoted asset. A payment in another asset is returned in
     * tokens: 20.00 of an order quoted in XRP says nothing about how many RLUSD that is.
     */
    public function testAPaymentInAnotherAssetIsNotConvertedWithTheOrdersRate(): void
    {
        $this->paid('B', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '50'], ledgerIndex: 22475012);
        $this->trustline(self::CUSTOMER);

        $preview = $this->service()->preview($this->request(
            payment: 'B',
            purpose: Refund::PURPOSE_WRONG_ASSET_RETURN,
            fiatAmount: '20.00',
            orderValue: '220.00',
            ownWalletConfirmed: true,
        ));

        self::assertSame([RefundNotice::FIAT_BASIS_MISMATCH], self::codes($preview->blockers()));
        self::assertNull($preview->amount);
    }

    public function testASurplusReturnIsLimitedToWhatWasPaidInExcess(): void
    {
        $this->paid('A', '110000000');

        $preview = $this->service()->preview($this->request(purpose: Refund::PURPOSE_SURPLUS_RETURN, amount: '10.000001', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::AMOUNT_EXCEEDS_SURPLUS], self::codes($preview->blockers()));
        self::assertSame(['surplus' => '10'], $preview->notice(RefundNotice::AMOUNT_EXCEEDS_SURPLUS)?->context);

        $refund = $this->service()->create($this->request(purpose: Refund::PURPOSE_SURPLUS_RETURN, everything: true, ownWalletConfirmed: true));
        self::assertSame('10', $refund->amount->value);
        self::assertSame(Refund::PURPOSE_SURPLUS_RETURN, $refund->purpose);

        $none = $this->service()->preview($this->request(purpose: Refund::PURPOSE_SURPLUS_RETURN, amount: '1', ownWalletConfirmed: true));
        self::assertSame(['surplus' => '0'], $none->notice(RefundNotice::AMOUNT_EXCEEDS_SURPLUS)?->context);
    }

    /* ---------- the payment being refunded ---------- */

    public function testATransactionTheOrderDidNotReceiveCannotBeRefunded(): void
    {
        $this->paid('A', '100000000');

        $preview = $this->service()->preview($this->request(payment: 'something else', amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::SOURCE_NOT_FOUND], self::codes($preview->blockers()));
        self::assertSame(0, $this->node->count('tx'));
    }

    /**
     * The stored row and the ledger must be the same payment.
     */
    public function testAStoredPaymentTheLedgerTellsDifferentlyIsNotRefunded(): void
    {
        $this->paid('A', '100000000');

        foreach (['Destination' => self::NAMED, 'DestinationTag' => self::TAG + 1, 'Account' => self::NAMED] as $field => $value) {
            $onLedger = $this->txResult('A', '100000000', self::CUSTOMER, 22475010, null);
            $onLedger[$field] = $value;
            $this->node->answer('tx ' . self::hash('A'), $onLedger);

            $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

            self::assertSame([RefundNotice::SOURCE_NOT_FOUND], self::codes($preview->blockers()), $field);
        }

        $failed = $this->txResult('A', '100000000', self::CUSTOMER, 22475010, null);
        $failed['meta']['TransactionResult'] = 'tecPATH_DRY';
        $this->node->answer('tx ' . self::hash('A'), $failed);
        self::assertSame([RefundNotice::SOURCE_NOT_FOUND], self::codes($this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true))->blockers()));
    }

    public function testAnIntentOfAnotherChainOrAnUnknownPurposeIsAProgrammingError(): void
    {
        $this->paid('A', '100000000');

        try {
            $this->service('XAHAU')->preview($this->request(amount: '40'));
            self::fail('An XRPL intent is not for the Xahau service.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);

        $this->service()->preview($this->request(purpose: 'gift', amount: '40'));
    }

    /* ---------- what the ledger would do ---------- */

    /**
     * The recorded payment: the customer spent XRP, the shop received RLUSD through the
     * ledger's exchange. The customer holds no RLUSD trust line, so the refund in the received
     * asset would be refused by the ledger — and the reason names both assets.
     */
    public function testACustomerWhoPaidThroughTheExchangeAndCannotTakeTheReceivedAssetBack(): void
    {
        $recorded = self::recorded('tx-incoming-pathed.json');
        $this->node->answer('tx ' . $recorded['hash'], $recorded);
        $this->transactions->saveTransactions([new XrplTransaction(
            network: 'testnet',
            ledgerIndex: (string) $recorded['ledger_index'],
            hash: $recorded['hash'],
            ctid: $recorded['ctid'],
            account: $recorded['Account'],
            destination: $recorded['Destination'],
            destinationTag: $recorded['DestinationTag'],
            date: $recorded['date'],
            meta: $recorded['meta'],
            tx: $recorded,
        )]);
        $intent = self::rlusdIntent('0.2');

        $preview = $this->service()->preview(new RefundRequest(
            intent: $intent,
            platformReference: 'order-transaction-1',
            sourceHash: $recorded['hash'],
            purpose: Refund::PURPOSE_ORDER_REFUND,
            everything: true,
            ownWalletConfirmed: true,
            createdBy: 'admin',
        ));

        self::assertSame([RefundNotice::TRUSTLINE_MISSING], self::codes($preview->blockers()));
        self::assertSame(['spent' => 'XRP', 'received' => self::RLUSD], $preview->notice(RefundNotice::PAID_ACROSS_ASSETS)?->context);
        self::assertFalse($preview->notice(RefundNotice::PAID_ACROSS_ASSETS)?->blocks);
        self::assertSame('0.2', $preview->amount?->value);
    }

    public function testATokenRefundTheLedgerWouldAcceptIsCreated(): void
    {
        $this->paidRlusd('A', '86.40');
        $this->trustline(self::CUSTOMER);

        $refund = $this->service()->create($this->request(self::rlusdIntent(), amount: '21.6', ownWalletConfirmed: true));

        self::assertSame('21.6', $refund->amount->value);
    }

    /**
     * Nothing is created on a guess: with the node down there is no fresh answer about the tag.
     */
    public function testWithTheNodeDownNothingIsCreated(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('tx ' . self::hash('A'), ['status' => 'error', 'error' => 'noNetwork']);

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::LEDGER_UNAVAILABLE], self::codes($preview->blockers()), 'and no verdict on the tag from half an answer');
        $this->assertRefused(RefundNotice::LEDGER_UNAVAILABLE, $this->request(amount: '40', ownWalletConfirmed: true));
        self::assertSame([], $this->refunds->storedRows());
    }

    public function testWithTheDestinationUnreachableNothingIsCreated(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('account_info ' . self::CUSTOMER, ['status' => 'error', 'error' => 'noNetwork']);

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertSame([RefundNotice::LEDGER_UNAVAILABLE], self::codes($preview->blockers()));
    }

    public function testWithoutTheValidatedLedgerNothingIsCreated(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('server_state', ['status' => 'error', 'error' => 'noNetwork']);

        $this->assertRefused(RefundNotice::LEDGER_UNAVAILABLE, $this->request(amount: '40', ownWalletConfirmed: true));
        self::assertSame([], $this->refunds->storedRows());
    }

    /* ---------- two requests ---------- */

    /**
     * A payment on the ledger could not be told to belong to one or the other.
     */
    public function testASecondOpenRefundToTheSameDestinationTagAssetAndAmountIsRefused(): void
    {
        $this->paid('A', '100000000');
        $first = $this->service()->create($this->request(amount: '40', ownWalletConfirmed: true));

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));
        self::assertSame([RefundNotice::DUPLICATE_OPEN_REFUND], self::codes($preview->blockers()));
        self::assertSame(['refund' => $first->id], $preview->notice(RefundNotice::DUPLICATE_OPEN_REFUND)?->context);

        self::assertTrue($this->service()->preview($this->request(amount: '40.5', ownWalletConfirmed: true))->isAllowed(), 'another amount can be told apart');

        $this->service()->cancel($first->id);
        self::assertTrue($this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true))->isAllowed(), 'a closed refund is no longer in the way');
    }

    /**
     * Two requests for the same payment arrive at the same moment, each fitting on its own:
     * 60 and 60 of 100. The second finds its number taken, reads again, and is refused.
     */
    public function testTwoSimultaneousRequestsCannotBothFitUnderOnePayment(): void
    {
        $this->paid('A', '100000000');
        $racing = new RacingRefundRepository($this->refunds, function (): void {
            $this->service()->create($this->request(amount: '60', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        });

        try {
            $this->service(repository: $racing)->create($this->request(amount: '60', ownWalletConfirmed: true));
            self::fail('120 of 100 must not be requested.');
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE));
        }

        self::assertCount(1, $this->refunds->storedRows());
        self::assertSame('60', $this->refunds->findBySource('testnet', self::hash('A'))[0]->amount->value);
    }

    /**
     * The other request is stored after this one computed its limit and before it is stored
     * itself (here: while it asks the node for the newest ledger). The number this one stores
     * under is the one its limit was computed with, not one counted afresh: so storage refuses
     * it, and the limit is computed again with the other request in it.
     */
    public function testARequestOvertakenBetweenReadingAndStoringIsNotStoredOnAStaleLimit(): void
    {
        $this->paid('A', '100000000');
        $node = new class($this->node, function (): void {
            $this->service()->create($this->request(amount: '60', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        }) implements \Psr\Http\Client\ClientInterface {
            private bool $raced = false;

            public function __construct(private readonly FakeNode $inner, private readonly \Closure $rival)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if (!$this->raced && str_contains((string) $request->getBody(), '"server_state"')) {
                    $this->raced = true;
                    ($this->rival)();
                }

                return $this->inner->sendRequest($request);
            }
        };
        $service = new RefundService(
            new XrplClient($node, new HttpFactory(), new HttpFactory()),
            $this->transactions,
            $this->refunds,
            new RecordingLogger(),
            $this->clock,
        );

        try {
            $service->create($this->request(amount: '60', ownWalletConfirmed: true));
            self::fail('120 of 100 must not be requested.');
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE));
        }

        self::assertCount(1, $this->refunds->storedRows());
    }

    /**
     * An order of 100 paid with 55 and 55: 10 in excess. Two surplus returns at the same
     * moment, 6 on one payment and 7 on the other. Their numbers do not collide — they belong
     * to different payments — so the one stored second looks again, finds 13 claimed of 10,
     * and withdraws itself.
     */
    public function testTwoSimultaneousSurplusReturnsOnTwoPaymentsCannotTogetherExceedTheExcess(): void
    {
        $this->paid('A', '55000000');
        $this->paid('B', '55000000', ledgerIndex: 22475012);
        $racing = new RacingRefundRepository($this->refunds, function (): void {
            $this->service()->create($this->request(payment: 'B', purpose: Refund::PURPOSE_SURPLUS_RETURN, amount: '7', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        });

        try {
            $this->service(repository: $racing)->create($this->request(purpose: Refund::PURPOSE_SURPLUS_RETURN, amount: '6', ownWalletConfirmed: true));
            self::fail('13 of an excess of 10 must not be requested.');
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has(RefundNotice::AMOUNT_EXCEEDS_SURPLUS));
        }

        $onB = $this->refunds->findBySource('testnet', self::hash('B'));
        $onA = $this->refunds->findBySource('testnet', self::hash('A'));
        self::assertTrue($onB[0]->isAwaitingExecution(), 'the one that was there first stays');
        self::assertTrue($onA[0]->isCancelled(), 'the one that came second is withdrawn, and stays on record');
        self::assertSame('3', $this->service()->overview(self::xrpIntent())->surplusReturnable);
    }

    /**
     * A refund of 70 was cancelled; while a new one of 100 is being created, the old one turns
     * out to have been paid after all. The new one is stored under a free number — nothing
     * new was created for the payment — looks again, finds 170 claimed of 100, and withdraws.
     */
    public function testARefundStoredWhileAnotherClaimGrewIsWithdrawn(): void
    {
        $this->paid('A', '100000000');
        $old = $this->service()->create($this->request(amount: '70', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        $cancelled = $old->cancelled(self::NOW);
        $this->refunds->update($cancelled, $old->version);
        $racing = new RacingRefundRepository($this->refunds, function () use ($cancelled): void {
            $this->refunds->update(
                $cancelled->executed(self::hash('paid after all'), self::VALIDATED_LEDGER + 5, self::NOW, self::MERCHANT, $cancelled->amount, Refund::MATCHED_BY_ATTACHED, self::NOW),
                $cancelled->version,
            );
        });

        try {
            $this->service(repository: $racing)->create($this->request(everything: true, ownWalletConfirmed: true));
            self::fail('170 of 100 must not stay requested.');
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has(RefundNotice::AMOUNT_EXCEEDS_REFUNDABLE));
        }

        $refunds = $this->refunds->findBySource('testnet', self::hash('A'));
        self::assertTrue($refunds[0]->isExecuted());
        self::assertTrue($refunds[1]->isCancelled());
        self::assertSame('30', $this->service()->overview(self::xrpIntent())->payments[0]->refundable);
    }

    public function testTheLaterOfTwoSimultaneousRequestsThatBothFitGetsTheNextNumber(): void
    {
        $this->paid('A', '100000000');
        $racing = new RacingRefundRepository($this->refunds, function (): void {
            $this->service()->create($this->request(amount: '30', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        });

        $refund = $this->service(repository: $racing)->create($this->request(amount: '60', ownWalletConfirmed: true));

        self::assertSame(2, $refund->serial);
        self::assertSame(
            ['30', '60'],
            array_map(static fn (Refund $stored): string => $stored->amount->value, $this->refunds->findBySource('testnet', self::hash('A'))),
        );
    }

    public function testStorageThatKeepsRefusingEndsInAConflictNotInALoop(): void
    {
        $this->paid('A', '100000000');
        $refusing = new class implements RefundRepositoryInterface {
            public int $inserts = 0;

            public function insert(Refund $refund): bool
            {
                $this->inserts++;

                return false;
            }

            public function update(Refund $refund, int $expectedVersion): bool
            {
                return false;
            }

            public function find(string $id): ?Refund
            {
                return null;
            }

            public function findByReference(string $network, string $reference): ?Refund
            {
                return null;
            }

            public function findBySource(string $network, string $sourceHash): array
            {
                return [];
            }

            public function findByPlatformReference(string $platformReference): array
            {
                return [];
            }

            public function findByDestination(string $network, string $payingAccount, string $destination): array
            {
                return [];
            }

            public function findWatched(string $network, string $payingAccount, int $closedSince): array
            {
                return [];
            }

            public function watchedAccounts(string $network, int $closedSince): array
            {
                return [];
            }
        };

        try {
            $this->service(repository: $refusing)->create($this->request(amount: '40', ownWalletConfirmed: true));
            self::fail('A refund that is not stored is not created.');
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has(RefundNotice::CONFLICT));
        }

        self::assertSame(3, $refusing->inserts);
    }

    /* ---------- the reference ---------- */

    /**
     * With the installation's key the reference follows from the payment and the number, so an
     * executed refund can be found on the ledger again without the table.
     */
    public function testTheReferenceIsDerivedFromThePaymentItRefundsWhenThereIsAKey(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service(referenceKey: 'installation-secret');

        $first = $service->create($this->request(amount: '10', ownWalletConfirmed: true));
        $second = $service->create($this->request(amount: '20', ownWalletConfirmed: true));

        self::assertSame(RefundService::deriveReference('installation-secret', 'XRPL', 'testnet', self::hash('A'), 1), $first->reference);
        self::assertSame(RefundService::deriveReference('installation-secret', 'XRPL', 'testnet', self::hash('A'), 2), $second->reference);
        self::assertNotSame($first->reference, $second->reference);
        self::assertSame(
            strtoupper(hash_hmac('sha256', 'ledger-direct-refund-v1|XRPL|testnet|' . self::hash('A') . '|1', 'installation-secret')),
            $first->reference,
            'the derivation is part of the contract',
        );
        self::assertNotSame(
            $first->reference,
            RefundService::deriveReference('another-secret', 'XRPL', 'testnet', self::hash('A'), 1),
            'without the key nobody can compute which refund belongs to which payment',
        );
    }

    public function testWithoutAKeyTheReferenceIsRandom(): void
    {
        $this->paid('A', '100000000');

        $first = $this->service()->create($this->request(amount: '10', ownWalletConfirmed: true));
        $second = $this->service()->create($this->request(amount: '20', ownWalletConfirmed: true));

        self::assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $first->reference);
        self::assertNotSame($first->reference, $second->reference);
    }

    /* ---------- the payment request and cancelling ---------- */

    public function testThePaymentRequestCarriesTheReferenceAndATagOnlyWhenThereIsOne(): void
    {
        $this->paid('A', '100000000');
        $refund = $this->service()->create($this->request(amount: '40.5', ownWalletConfirmed: true));

        self::assertSame(
            'https://xrplf.org//send?to=' . self::CUSTOMER . '&amount=40.5&invoiceid=' . $refund->reference . '&network=TESTNET',
            $this->service()->paymentUri($refund),
        );
    }

    /**
     * A cancelled or executed refund is not offered for signing again.
     */
    public function testOnlyARefundThatAwaitsExecutionHasARequestToSign(): void
    {
        $this->paid('A', '100000000');
        $open = $this->service()->create($this->request(amount: '40', ownWalletConfirmed: true));
        $cancelled = $open->cancelled(self::NOW);
        $executed = $open->executed(self::hash('execution'), self::VALIDATED_LEDGER + 5, self::NOW, self::MERCHANT, $open->amount, Refund::MATCHED_BY_KEY, self::NOW);

        self::assertNotNull($this->service()->paymentUri($open));
        self::assertNull($this->service()->paymentUri($cancelled));
        self::assertNull($this->service()->paymentUri($executed));
    }

    public function testCancellingWithdrawsAnOpenRefundAndNothingElse(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->clock->advance(120);

        $cancellation = $service->cancel($refund->id);

        self::assertTrue($cancellation->isCancelled());
        self::assertFalse($cancellation->couldNotVerify());
        self::assertTrue($cancellation->reconciled?->complete);
        self::assertSame(self::NOW + 120, $cancellation->refund?->closedAt);
        self::assertEquals($cancellation->refund, $service->find($refund->id));

        $again = $service->cancel($refund->id);
        self::assertEquals($cancellation->refund, $again->refund, 'cancelling twice changes nothing');
        self::assertNull($again->reconciled, 'and does not read the ledger for a refund that is not open');

        self::assertNull($service->cancel(str_repeat('0', 32))->refund);
    }

    /**
     * The merchant signed a moment ago and now clicks "cancel": the ledger is read first, and
     * the refund comes back executed.
     */
    public function testARefundThatWasJustPaidIsFoundExecutedInsteadOfBeingCancelled(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => self::VALIDATED_LEDGER + 2, 'transactions' => [
            self::outgoing('payment', self::CUSTOMER, '40000000', ledgerIndex: self::VALIDATED_LEDGER + 1, invoiceId: $refund->reference),
        ]]);
        $this->node->answer('server_state', self::serverState(self::VALIDATED_LEDGER + 2));

        $cancellation = $service->cancel($refund->id);

        self::assertFalse($cancellation->isCancelled());
        self::assertTrue($cancellation->refund?->isExecuted());
        self::assertSame(self::hash('payment'), $cancellation->refund?->executedHash);
        self::assertSame(Refund::MATCHED_BY_REFERENCE, $cancellation->refund?->matchedBy);
        self::assertEquals([$cancellation->refund], $cancellation->reconciled?->executed);
    }

    /**
     * Cancelling frees the amount for another refund. With the ledger unread nobody knows
     * whether the first was paid, so it is not cancelled.
     */
    public function testARefundIsNotCancelledWhileTheLedgerCannotBeRead(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'error', 'error' => 'noNetwork']);

        $cancellation = $service->cancel($refund->id);

        self::assertFalse($cancellation->isCancelled());
        self::assertTrue($cancellation->couldNotVerify());
        self::assertTrue($service->find($refund->id)?->isAwaitingExecution());
    }

    /**
     * The node that answers is far behind the newest validated ledger: what it does not show
     * is not known not to have happened, and the refund is not cancelled on its word.
     */
    public function testARefundIsNotCancelledOnTheWordOfANodeThatIsBehind(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->node
            ->answer('server_state', self::serverState(self::VALIDATED_LEDGER + 500))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => self::VALIDATED_LEDGER + 3, 'transactions' => []]);

        $cancellation = $service->cancel($refund->id);

        self::assertTrue($cancellation->couldNotVerify());
        self::assertStringContainsString('covers ledgers up to', (string) $cancellation->reconciled?->error);
        self::assertTrue($service->find($refund->id)?->isAwaitingExecution());
    }

    /**
     * After a test network was reset its ledger starts again far below the one the refund was
     * created in. No payment for it can ever be found there, and it can be withdrawn.
     */
    public function testARefundFromBeforeATestnetResetCanBeCancelled(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->node->answer('server_state', self::serverState(5000));

        $cancellation = $service->cancel($refund->id);

        self::assertTrue($cancellation->isCancelled());
        self::assertEquals([$refund], $cancellation->reconciled?->unreachable);
        self::assertSame(0, $this->node->count('account_tx') - 1, 'only the preview read the account, the look before cancelling had nothing to read');
    }

    /* ---------- naming the transaction that paid ---------- */

    /**
     * Paid from another account of the merchant, a little less than asked, and found by nobody:
     * the merchant names the transaction, and what differs is written down.
     */
    public function testAPaymentTheMerchantNamesExecutesTheRefundWithWhatDiffersWrittenDown(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', destination: self::NAMED, destinationTag: '4711', destinationTagRepeat: '4711'));
        $payment = self::outgoingTx('by hand', self::NAMED, '39500000', tag: 4711, from: self::CUSTOMER, ledgerIndex: 22390000);
        $this->node->answer('tx ' . self::hash('by hand'), $payment);

        $executed = $service->attach($refund->id, strtolower(self::hash('by hand')));

        self::assertTrue($executed->isExecuted());
        self::assertSame(Refund::MATCHED_BY_ATTACHED, $executed->matchedBy);
        self::assertSame([Refund::DEVIATION_OTHER_ACCOUNT, Refund::DEVIATION_AMOUNT], $executed->deviations);
        self::assertSame(self::CUSTOMER, $executed->executedAccount);
        self::assertSame('39.5', $executed->delivered?->value);
        self::assertSame(848500511 + 946684800, $executed->executedAt);
        self::assertSame(22390000, $executed->executedLedger, 'also a payment from before the refund was created');
        self::assertEquals($executed, $service->find($refund->id));
    }

    public function testANamedPaymentAlsoExecutesACancelledRefund(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $service->cancel($refund->id);
        $this->node->answer('tx ' . self::hash('late'), self::outgoingTx('late', self::CUSTOMER, '40000000'));

        $executed = $service->attach($refund->id, self::hash('late'));

        self::assertSame([Refund::DEVIATION_AFTER_CANCEL], $executed->deviations);
    }

    /**
     * @param callable(array<string, mixed>): ?array<string, mixed> $change
     */
    #[DataProvider('paymentsThatAreNotTheRefund')]
    public function testATransactionThatIsNotAPaymentToTheRefundsDestinationWithItsTagAndAssetIsNotItsExecution(
        callable $change,
        string $code,
        array $context,
    ): void {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', destination: self::NAMED, destinationTag: '4711', destinationTagRepeat: '4711'));
        $answer = $change(self::outgoingTx('named', self::NAMED, '40000000', tag: 4711));
        $this->node->answer('tx ' . self::hash('named'), $answer ?? ['status' => 'error', 'error' => 'txnNotFound']);

        try {
            $service->attach($refund->id, self::hash('named'));
            self::fail("Must be refused with '{$code}'.");
        } catch (RefundRefusedException $refused) {
            self::assertSame($code, $refused->notices[0]->code);
            self::assertSame($context, $refused->notices[0]->context);
        }

        self::assertTrue($service->find($refund->id)?->isAwaitingExecution());
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): ?array<string, mixed>, string, array<string, string>}>
     */
    public static function paymentsThatAreNotTheRefund(): array
    {
        $with = static fn (string $field, mixed $value): callable => static function (array $tx) use ($field, $value): array {
            $tx[$field] = $value;

            return $tx;
        };
        $without = static fn (string $field): callable => static function (array $tx) use ($field): array {
            unset($tx[$field]);

            return $tx;
        };

        return [
            'not on the ledger' => [static fn (array $tx): ?array => null, RefundNotice::TRANSACTION_NOT_FOUND, []],
            'not validated yet' => [$with('validated', false), RefundNotice::TRANSACTION_NOT_VALIDATED, []],
            'refused by the ledger' => [
                static function (array $tx): array {
                    $tx['meta'] = ['TransactionResult' => 'tecDST_TAG_NEEDED'];

                    return $tx;
                },
                RefundNotice::TRANSACTION_FAILED,
                ['result' => 'tecDST_TAG_NEEDED'],
            ],
            'not a payment' => [$with('TransactionType', 'EscrowCreate'), RefundNotice::TRANSACTION_MISMATCH, ['field' => 'type']],
            'to another account' => [$with('Destination', self::CUSTOMER), RefundNotice::TRANSACTION_MISMATCH, ['field' => 'destination']],
            'with another tag' => [$with('DestinationTag', 4712), RefundNotice::TRANSACTION_MISMATCH, ['field' => 'tag']],
            'without the tag' => [$without('DestinationTag'), RefundNotice::TRANSACTION_MISMATCH, ['field' => 'tag']],
            'in another asset' => [
                static function (array $tx): array {
                    $tx['meta']['delivered_amount'] = ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '40'];

                    return $tx;
                },
                RefundNotice::TRANSACTION_MISMATCH,
                ['field' => 'asset'],
            ],
        ];
    }

    public function testWhatCannotBeNamedAsAnExecution(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $first = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $second = $service->create($this->request(amount: '40.5', ownWalletConfirmed: true));
        $this->node->answer('tx ' . self::hash('once'), self::outgoingTx('once', self::CUSTOMER, '40000000'));
        $service->attach($first->id, self::hash('once'));

        $expect = function (string $code, callable $attach): void {
            try {
                $attach();
                self::fail("Must be refused with '{$code}'.");
            } catch (RefundRefusedException $refused) {
                self::assertTrue($refused->has($code), $refused->getMessage());
            }
        };

        $expect(RefundNotice::ALREADY_EXECUTED, fn () => $service->attach($first->id, self::hash('once')));
        $expect(RefundNotice::TRANSACTION_ALREADY_ASSIGNED, fn () => $service->attach($second->id, self::hash('once')));
        $expect(RefundNotice::REFUND_NOT_FOUND, fn () => $service->attach(str_repeat('0', 32), self::hash('once')));
        $expect(RefundNotice::TRANSACTION_NOT_FOUND, fn () => $service->attach($second->id, 'not a hash'));

        $this->node->answer('tx ' . self::hash('down'), ['status' => 'error', 'error' => 'noNetwork']);
        $expect(RefundNotice::LEDGER_UNAVAILABLE, fn () => $service->attach($second->id, self::hash('down')));
    }

    /* ---------- paid by hand already? ---------- */

    /**
     * Before a refund is created the preview shows what the paying account already sent to the
     * destination since the payment — the one protection that holds without the refund table.
     */
    public function testThePreviewShowsWhatWasAlreadySentToTheDestinationSinceThePayment(): void
    {
        $this->paid('A', '100000000');
        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'transactions' => [
            self::outgoing('by hand', self::CUSTOMER, '40000000', ledgerIndex: 22475020),
            self::outgoing('to somebody else', self::NAMED, '40000000', ledgerIndex: 22475021),
            self::outgoing('refused', self::CUSTOMER, '40000000', ledgerIndex: 22475022, result: 'tecNO_DST'),
            self::outgoing('incoming', self::MERCHANT, '40000000', ledgerIndex: 22475023, from: self::CUSTOMER),
        ]]);

        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));

        self::assertTrue($preview->isAllowed(), 'advice, not a refusal');
        self::assertSame(
            ['hash' => self::hash('by hand'), 'amount' => '40', 'asset' => 'XRP', 'tag' => ''],
            $preview->notice(RefundNotice::PAID_BEFORE)?->context,
        );
        self::assertCount(1, array_filter($preview->notices, static fn (RefundNotice $notice): bool => $notice->code === RefundNotice::PAID_BEFORE));
        self::assertSame(22475010, $this->lastAccountTxCall()['ledger_index_min'], "from the payment's own ledger on");
    }

    public function testTheExecutionOfARefundOnRecordIsNotShownAsPaidByHand(): void
    {
        $this->paid('A', '100000000');
        $service = $this->service();
        $refund = $service->create($this->request(amount: '40', ownWalletConfirmed: true));
        $this->node->answer('tx ' . self::hash('execution'), self::outgoingTx('execution', self::CUSTOMER, '40000000'));
        $service->attach($refund->id, self::hash('execution'));
        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'transactions' => [
            self::outgoing('execution', self::CUSTOMER, '40000000', ledgerIndex: 22475030),
        ]]);

        $preview = $service->preview($this->request(amount: '10', ownWalletConfirmed: true));

        self::assertFalse($preview->has(RefundNotice::PAID_BEFORE));
        self::assertSame([], $preview->notices);
    }

    /**
     * A history that cannot be read is said to be unread. It is not "nothing was sent".
     */
    public function testAHistoryThatCannotBeReadIsSaidToBeUnread(): void
    {
        $this->paid('A', '100000000');

        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'error', 'error' => 'noNetwork']);
        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));
        self::assertTrue($preview->has(RefundNotice::PRIOR_PAYMENTS_NOT_CHECKED));
        self::assertTrue($preview->isAllowed());

        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'transactions' => [], 'marker' => 'more']);
        $preview = $this->service()->preview($this->request(amount: '40', ownWalletConfirmed: true));
        self::assertTrue($preview->has(RefundNotice::PRIOR_PAYMENTS_NOT_CHECKED), 'more pages than a preview reads');

        $this->node->answer('tx ' . self::hash('A'), ['status' => 'error', 'error' => 'txnNotFound']);
        $preview = $this->service()->preview($this->request(amount: '40', destination: self::NAMED, destinationTag: '1', destinationTagRepeat: '1'));
        self::assertTrue($preview->has(RefundNotice::PRIOR_PAYMENTS_NOT_CHECKED), 'a payment the ledger no longer has says nothing about where to start');
    }

    /* ---------- Xahau ---------- */

    public function testXahauRefundsItsNativeAssetThroughItsOwnNodes(): void
    {
        $xahauRefunds = new InMemoryRefundRepository('XAHAU');
        $intent = PaymentIntent::quote(
            type: 'xahau-xah-payment',
            chain: 'XAHAU',
            network: 'testnet',
            baseAsset: 'XAH',
            quoteCurrency: 'EUR',
            pairing: 'XAH/EUR',
            exchangeRate: 0.05,
            amountRequested: 100.0,
            destinationAccount: self::MERCHANT,
            destinationTag: self::TAG,
        );
        $this->paid('A', '100000000');
        $this->node->answer('server_state', 'xahau-server-state.json');

        $service = $this->service('XAHAU', $xahauRefunds);
        $refund = $service->create($this->request($intent, amount: '40', ownWalletConfirmed: true));

        self::assertSame('XAHAU', $refund->chain);
        self::assertSame(14139507, $refund->createdLedger);
        self::assertSame('XAH', $xahauRefunds->storedRows()[0]['asset_currency']);
        self::assertStringEndsWith('&network=XAHAUTESTNET', (string) $service->paymentUri($refund));
        self::assertSame(['https://xahau-test.net/'], array_values(array_unique(array_column($this->node->calls, 'url'))));
    }

    /* ---------- helpers ---------- */

    private function service(string $chain = 'XRPL', ?RefundRepositoryInterface $repository = null, ?string $referenceKey = null): RefundService
    {
        return new RefundService(
            new XrplClient($this->node, new HttpFactory(), new HttpFactory(), $chain),
            $this->transactions,
            $repository ?? $this->refunds,
            new RecordingLogger(),
            $this->clock,
            $chain,
            $referenceKey,
        );
    }

    private function request(
        ?PaymentIntent $intent = null,
        string $payment = 'A',
        string $purpose = Refund::PURPOSE_ORDER_REFUND,
        ?string $amount = null,
        ?string $fiatAmount = null,
        ?string $orderValue = null,
        bool $everything = false,
        ?string $destination = null,
        ?string $destinationTag = null,
        ?string $destinationTagRepeat = null,
        bool $ownWalletConfirmed = false,
        ?string $reason = null,
        ?string $createdBy = 'admin',
    ): RefundRequest {
        return new RefundRequest(
            intent: $intent ?? self::xrpIntent(),
            platformReference: 'order-transaction-1',
            sourceHash: self::hash($payment),
            purpose: $purpose,
            amount: $amount,
            fiatAmount: $fiatAmount,
            orderValue: $orderValue,
            everything: $everything,
            destination: $destination,
            destinationTag: $destinationTag,
            destinationTagRepeat: $destinationTagRepeat,
            ownWalletConfirmed: $ownWalletConfirmed,
            reason: $reason,
            createdBy: $createdBy,
        );
    }

    private function assertRefused(string $code, RefundRequest $request): void
    {
        try {
            $this->service()->create($request);
            self::fail("The refund must be refused with '{$code}'.");
        } catch (RefundRefusedException $refused) {
            self::assertTrue($refused->has($code), $refused->getMessage());
        }
    }

    /**
     * A payment the order received: stored as the sync stores it, and on the ledger as `tx`
     * returns it.
     *
     * @param string|array<string, string> $delivered drops, or a token amount
     */
    private function paid(string $name, string|array $delivered, ?int $sourceTag = null, string $sender = self::CUSTOMER, int $ledgerIndex = 22475010): void
    {
        $result = $this->txResult($name, $delivered, $sender, $ledgerIndex, $sourceTag);
        $this->node->answer('tx ' . self::hash($name), $result);

        $this->transactions->saveTransactions([new XrplTransaction(
            network: 'testnet',
            ledgerIndex: (string) $ledgerIndex,
            hash: self::hash($name),
            ctid: 'C156F10200040001',
            account: $sender,
            destination: self::MERCHANT,
            destinationTag: self::TAG,
            date: 848500511,
            meta: $result['meta'],
            tx: $result,
        )]);
    }

    private function paidRlusd(string $name, string $value): void
    {
        $this->paid($name, ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => $value]);
    }

    /** The account opens the RLUSD trust line, as recorded. */
    private function trustline(string $account): void
    {
        $this->node->answer('account_lines ' . $account, self::recorded('account-lines-customer-with-trustline.json'));
    }

    /**
     * The recorded payment with other values: what `tx` answers for the named payment.
     *
     * @param string|array<string, string> $delivered
     * @return array<string, mixed>
     */
    private function txResult(string $name, string|array $delivered, string $sender, int $ledgerIndex, ?int $sourceTag): array
    {
        $result = self::recorded('tx-incoming-pathed.json');
        unset($result['SendMax']);

        $result['hash'] = self::hash($name);
        $result['Account'] = $sender;
        $result['Destination'] = self::MERCHANT;
        $result['DestinationTag'] = self::TAG;
        $result['Amount'] = $delivered;
        $result['DeliverMax'] = $delivered;
        $result['ledger_index'] = $ledgerIndex;
        $result['inLedger'] = $ledgerIndex;
        $result['meta']['delivered_amount'] = $delivered;
        if ($sourceTag !== null) {
            $result['SourceTag'] = $sourceTag;
        }

        return $result;
    }

    /**
     * A transaction the merchant's account sent, as an entry of `account_tx`.
     *
     * @return array<string, mixed>
     */
    private static function outgoing(
        string $name,
        string $destination,
        string $drops,
        int $ledgerIndex = 22475030,
        ?int $tag = null,
        ?string $invoiceId = null,
        string $result = 'tesSUCCESS',
        string $from = self::MERCHANT,
    ): array {
        $tx = [
            'Account' => $from,
            'Amount' => $drops,
            'Destination' => $destination,
            'Fee' => '12',
            'Flags' => 0,
            'TransactionType' => 'Payment',
            'date' => 848500511,
            'hash' => self::hash($name),
            'ledger_index' => $ledgerIndex,
        ];
        if ($tag !== null) {
            $tx['DestinationTag'] = $tag;
        }
        if ($invoiceId !== null) {
            $tx['InvoiceID'] = $invoiceId;
        }

        $meta = ['TransactionIndex' => 1, 'TransactionResult' => $result];
        if ($result === 'tesSUCCESS') {
            $meta['delivered_amount'] = $drops;
        }

        return ['meta' => $meta, 'tx' => $tx, 'validated' => true];
    }

    /**
     * The same as `tx` returns it.
     *
     * @return array<string, mixed>
     */
    private static function outgoingTx(
        string $name,
        string $destination,
        string $drops,
        ?int $tag = null,
        string $from = self::MERCHANT,
        int $ledgerIndex = 22475030,
    ): array {
        $entry = self::outgoing($name, $destination, $drops, $ledgerIndex, $tag, from: $from);

        return $entry['tx'] + ['meta' => $entry['meta'], 'validated' => true, 'status' => 'success'];
    }

    /**
     * The recorded server_state with another newest validated ledger.
     *
     * @return array<string, mixed>
     */
    private static function serverState(int $validatedLedger): array
    {
        $state = FakeNode::result('server-state.json');
        $state['state']['validated_ledger']['seq'] = $validatedLedger;

        return $state;
    }

    /**
     * @return array<string, mixed> the parameters of the last account_tx call
     */
    private function lastAccountTxCall(): array
    {
        $calls = array_values(array_filter($this->node->calls, static fn (array $call): bool => $call['method'] === 'account_tx'));

        return $calls[count($calls) - 1]['params'];
    }

    private static function xrpIntent(float $requested = 100.0): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.2,
            amountRequested: $requested,
            destinationAccount: self::MERCHANT,
            destinationTag: self::TAG,
        );
    }

    /** 80.00 at 0.92592592592593 per token is 86.40. */
    private static function rlusdIntent(string $requested = '86.40'): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'EUR',
            pairing: 'RLUSD/EUR',
            exchangeRate: 0.92592592592593,
            amountRequested: ['currency' => self::RLUSD, 'value' => $requested, 'issuer' => self::ISSUER],
            destinationAccount: self::MERCHANT,
            destinationTag: self::TAG,
        );
    }

    /**
     * @param list<RefundNotice> $notices
     * @return list<string>
     */
    private static function codes(array $notices): array
    {
        return array_map(static fn (RefundNotice $notice): string => $notice->code, $notices);
    }

    private static function hash(string $name): string
    {
        return strtoupper(hash('sha256', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private static function recorded(string $fixture): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR)['result'];
    }
}
