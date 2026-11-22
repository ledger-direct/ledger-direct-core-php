<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundChange;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundFinding;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerTransaction;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a transaction from the paying account means for its refunds: one test per rule.
 */
final class RefundMatcherTest extends TestCase
{
    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const ELSEWHERE = 'rB2CxqjRjUkbebPZ6MgnXEaoSUFrmfsC9';
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    /** The ledger the refunds in these tests are created in. */
    private const CREATED = 1000;
    private const NOW = 1_800_000_000;
    private const WEEK = 604800;

    public function testAnExactPaymentAfterTheRequestExecutesIt(): void
    {
        $refund = self::refund(1, '40', tag: 4711);

        $changes = self::match(self::payment('P', '40000000', tag: 4711, ledger: self::CREATED + 1), [$refund]);

        self::assertCount(1, $changes);
        self::assertTrue($changes[0]->isExecution());
        self::assertSame($refund, $changes[0]->before);

        $executed = $changes[0]->after;
        self::assertTrue($executed->isExecuted());
        self::assertSame(self::hash('P'), $executed->executedHash);
        self::assertSame(self::CREATED + 1, $executed->executedLedger);
        self::assertSame(848500511 + 946684800, $executed->executedAt, 'the time of the ledger, not of the look');
        self::assertSame(self::NOW, $executed->closedAt);
        self::assertSame(self::MERCHANT, $executed->executedAccount);
        self::assertSame('40', $executed->delivered?->value);
        self::assertSame(Refund::MATCHED_BY_KEY, $executed->matchedBy);
        self::assertSame([], $executed->deviations);
    }

    /**
     * A refund is created after its ledger was validated, so a payment in that very ledger was
     * made before the request existed.
     */
    #[DataProvider('ledgersNotAfterTheRequest')]
    public function testAPaymentFromBeforeTheRequestIsNotItsExecution(int $ledger): void
    {
        $changes = self::match(self::payment('P', '40000000', ledger: $ledger), [self::refund(1, '40')]);

        self::assertSame([RefundFinding::PAID_BEFORE], self::codes($changes));
        self::assertTrue($changes[0]->after->isAwaitingExecution());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function ledgersNotAfterTheRequest(): array
    {
        return ['the ledger the request was created in' => [self::CREATED], 'an earlier ledger' => [self::CREATED - 50]];
    }

    /**
     * The tag belongs to the key in both directions, and no tag is not tag 0.
     */
    #[DataProvider('tagsThatDoNotMatch')]
    public function testAPaymentWithAnotherTagOrNoneIsAFindingNeverTheExecution(?int $asked, ?int $paid): void
    {
        $changes = self::match(self::payment('P', '40000000', tag: $paid), [self::refund(1, '40', tag: $asked)]);

        self::assertSame([RefundFinding::TAG_MISMATCH], self::codes($changes));
        self::assertTrue($changes[0]->after->isAwaitingExecution());
        self::assertSame($paid, $changes[0]->finding?->destinationTag);
        self::assertSame('40', $changes[0]->finding?->delivered?->value);
        self::assertSame(self::CUSTOMER, $changes[0]->finding?->destination);
        self::assertSame(self::NOW, $changes[0]->finding?->recordedAt);
    }

    /**
     * @return array<string, array{?int, ?int}>
     */
    public static function tagsThatDoNotMatch(): array
    {
        return [
            'a tag was asked for, none was sent' => [4711, null],
            'another tag was sent' => [4711, 4712],
            'no tag was asked for, one was sent' => [null, 4711],
            'no tag was asked for, tag 0 was sent' => [null, 0],
            'tag 0 was asked for, none was sent' => [0, null],
        ];
    }

    public function testTagZeroExecutesARefundWithTagZero(): void
    {
        $changes = self::match(self::payment('P', '40000000', tag: 0), [self::refund(1, '40', tag: 0)]);

        self::assertTrue($changes[0]->isExecution());
    }

    public function testOneDropMoreOrLessIsAnotherAmount(): void
    {
        foreach (['40000001', '39999999'] as $drops) {
            $changes = self::match(self::payment('P', $drops), [self::refund(1, '40')]);

            self::assertSame([RefundFinding::AMOUNT_MISMATCH], self::codes($changes));
            self::assertTrue($changes[0]->after->isAwaitingExecution());
        }
    }

    public function testTheSameNumberInAnotherAssetIsAnotherAmount(): void
    {
        $token = self::payment('P', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '40']);

        self::assertSame([RefundFinding::AMOUNT_MISMATCH], self::codes(self::match($token, [self::refund(1, '40')])));

        $tokenRefund = self::refund(1, '40', asset: [self::RLUSD, self::ISSUER]);
        self::assertTrue(self::match($token, [$tokenRefund])[0]->isExecution());
        self::assertSame(
            [RefundFinding::AMOUNT_MISMATCH],
            self::codes(self::match(self::payment('Q', ['currency' => self::RLUSD, 'issuer' => self::MERCHANT, 'value' => '40']), [$tokenRefund])),
            'the same code from another issuer is another asset',
        );
    }

    /**
     * Only what the delivered amount says counts: `Amount` 5 with a partial payment delivered 1.
     */
    public function testAPartialPaymentCountsWithWhatItDelivered(): void
    {
        $entry = self::entry('P', ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '5']);
        $entry['tx']['Flags'] = 131072;
        $entry['meta']['delivered_amount'] = ['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '1'];
        $partial = LedgerTransaction::fromAccountTx($entry);

        self::assertSame(
            [RefundFinding::AMOUNT_MISMATCH],
            self::codes(self::match($partial, [self::refund(1, '5', asset: [self::RLUSD, self::ISSUER])])),
        );
        self::assertTrue(self::match($partial, [self::refund(1, '1', asset: [self::RLUSD, self::ISSUER])])[0]->isExecution());
    }

    public function testAPaymentExecutesTheRequestItFitsAndLeavesTheOthersAlone(): void
    {
        $forty = self::refund(1, '40');
        $fifty = self::refund(2, '50');

        $changes = self::match(self::payment('P', '50000000'), [$forty, $fifty]);

        self::assertCount(1, $changes);
        self::assertSame($fifty, $changes[0]->before);
        self::assertTrue($changes[0]->isExecution());
    }

    /**
     * A payment the ledger refused is in the sender's list, validated, and delivered nothing.
     */
    public function testARefusedPaymentIsAFindingOnEveryOpenRequestToThatDestination(): void
    {
        $open = self::refund(1, '40', tag: 4711);
        $alsoOpen = self::refund(2, '50');
        $cancelled = self::refund(3, '60')->cancelled(self::NOW - 100);
        $createdLater = self::refund(4, '70', createdLedger: self::CREATED + 500);

        $changes = self::match(
            self::payment('P', '40000000', result: 'tecDST_TAG_NEEDED'),
            [$open, $alsoOpen, $cancelled, $createdLater],
        );

        self::assertSame([RefundFinding::FAILED_ATTEMPT, RefundFinding::FAILED_ATTEMPT], self::codes($changes));
        self::assertSame([$open, $alsoOpen], array_column($changes, 'before'));
        self::assertSame('tecDST_TAG_NEEDED', $changes[0]->finding?->result);
        self::assertNull($changes[0]->finding?->delivered);
        self::assertTrue($changes[0]->after->isAwaitingExecution(), 'the request stays open');
    }

    public function testAPaymentThatFitsACancelledRequestIsAFindingOnIt(): void
    {
        $cancelled = self::refund(1, '40')->cancelled(self::NOW - 100);

        $changes = self::match(self::payment('P', '40000000'), [$cancelled]);

        self::assertSame([RefundFinding::AFTER_CANCEL], self::codes($changes));
        self::assertTrue($changes[0]->after->isCancelled(), 'the merchant decides what it was');
    }

    public function testASecondPaymentThatFitsAnExecutedRequestIsADuplicate(): void
    {
        $executed = self::executed(self::refund(1, '40'), 'first');

        $changes = self::match(self::payment('second', '40000000', ledger: self::CREATED + 20), [$executed]);

        self::assertSame([RefundFinding::DUPLICATE], self::codes($changes));
        self::assertSame(self::hash('first'), $changes[0]->after->executedHash);
    }

    public function testTheTransactionThatExecutedARefundIsNothingElseAsWell(): void
    {
        $executed = self::executed(self::refund(1, '40'), 'first');
        $open = self::refund(2, '40', tag: 1);

        self::assertSame([], self::match(self::payment('first', '40000000', ledger: self::CREATED + 10), [$executed, $open]));
    }

    /**
     * An open request comes before a cancelled or executed one with the same key: the payment
     * is taken as the answer to the request that is still waiting.
     */
    public function testAnOpenRequestIsPreferredToAClosedOneWithTheSameKey(): void
    {
        $executed = self::executed(self::refund(1, '40'), 'first');
        $open = self::refund(2, '40', createdLedger: self::CREATED + 100);

        $changes = self::match(self::payment('second', '40000000', ledger: self::CREATED + 200), [$executed, $open]);

        self::assertCount(1, $changes);
        self::assertSame($open, $changes[0]->before);
        self::assertTrue($changes[0]->isExecution());
    }

    /* ---------- the reference ---------- */

    public function testAPaymentCarryingTheReferenceExecutesThatRefund(): void
    {
        $refund = self::refund(1, '40', tag: 4711);

        $changes = self::match(self::payment('P', '40000000', tag: 4711, invoiceId: $refund->reference), [$refund], $refund);

        self::assertTrue($changes[0]->isExecution());
        self::assertSame(Refund::MATCHED_BY_REFERENCE, $changes[0]->after->matchedBy);
    }

    /**
     * The reference says which refund was meant. It never stands in for the tag: a payment
     * that names a refund with a tag and carries none is a finding on that refund — and it does
     * not execute another request it happens to fit either.
     */
    public function testTheReferenceNeverReplacesTheTag(): void
    {
        $withTag = self::refund(1, '40', tag: 4711);
        $withoutTag = self::refund(2, '40', tag: null, createdLedger: self::CREATED + 1);

        $changes = self::match(self::payment('P', '40000000', invoiceId: $withTag->reference), [$withTag, $withoutTag], $withTag);

        self::assertSame([RefundFinding::TAG_MISMATCH], self::codes($changes));
        self::assertSame($withTag, $changes[0]->before);
        self::assertTrue($changes[0]->after->isAwaitingExecution());
    }

    public function testTheReferenceNeverReplacesTheAmount(): void
    {
        $refund = self::refund(1, '40');

        $changes = self::match(self::payment('P', '39000000', invoiceId: $refund->reference), [$refund], $refund);

        self::assertSame([RefundFinding::AMOUNT_MISMATCH], self::codes($changes));
    }

    /**
     * The merchant changed the destination in the wallet before signing: recognised by the
     * reference the payment still carries, and written on the refund with where the money went.
     */
    public function testAPaymentCarryingTheReferenceToAnotherAccountIsAFinding(): void
    {
        $refund = self::refund(1, '40');

        $changes = self::match(self::payment('P', '40000000', destination: self::ELSEWHERE, invoiceId: $refund->reference), [], $refund);

        self::assertSame([RefundFinding::DESTINATION_MISMATCH], self::codes($changes));
        self::assertSame(self::ELSEWHERE, $changes[0]->finding?->destination);
        self::assertTrue($changes[0]->after->isAwaitingExecution());
    }

    public function testAPaymentCarryingTheReferenceOfAClosedRefundIsAFindingOnIt(): void
    {
        $cancelled = self::refund(1, '40')->cancelled(self::NOW - 100);
        $executed = self::executed(self::refund(2, '50'), 'first');

        self::assertSame(
            [RefundFinding::AFTER_CANCEL],
            self::codes(self::match(self::payment('P', '40000000', invoiceId: $cancelled->reference), [$cancelled], $cancelled)),
        );
        self::assertSame(
            [RefundFinding::DUPLICATE],
            self::codes(self::match(self::payment('Q', '50000000', invoiceId: $executed->reference), [$executed], $executed)),
        );
    }

    /**
     * The same reference on a payment from before the refund existed: after the refund table
     * was lost and the refund created again, this is the old execution showing up.
     */
    public function testAPaymentCarryingTheReferenceFromBeforeTheRefundWasCreatedIsNotItsExecution(): void
    {
        $refund = self::refund(1, '40');

        $changes = self::match(self::payment('P', '40000000', ledger: self::CREATED - 10, invoiceId: $refund->reference), [$refund], $refund);

        self::assertSame([RefundFinding::PAID_BEFORE], self::codes($changes));
    }

    /* ---------- what is close, and what is not a payment ---------- */

    /**
     * Xahau's Remit, an EscrowCreate: validated, successful, to the destination, and nothing
     * this code can read an amount from.
     */
    public function testASuccessfulTransactionWithoutAReadableAmountIsAFinding(): void
    {
        $entry = self::entry('P', '40000000');
        $entry['tx']['TransactionType'] = 'Remit';
        unset($entry['meta']['delivered_amount'], $entry['tx']['Amount']);

        $changes = self::match(LedgerTransaction::fromAccountTx($entry), [self::refund(1, '40')]);

        self::assertSame([RefundFinding::UNREADABLE], self::codes($changes));
        self::assertSame('Remit', $changes[0]->finding?->transactionType);
        self::assertTrue($changes[0]->after->isAwaitingExecution());
    }

    /**
     * A payment to a destination that is still looked after is never left without a trace, also
     * when no request is open any more.
     */
    public function testAPaymentToADestinationWhoseRefundsAreClosedIsAFindingWhileTheyAreLookedAfter(): void
    {
        $closedYesterday = self::executed(self::refund(1, '40'), 'first', closedAt: self::NOW - 86400);
        $closedLongAgo = self::refund(2, '50')->cancelled(self::NOW - 2 * self::WEEK);

        $changes = self::match(self::payment('P', '7000000', ledger: self::CREATED + 30), [$closedYesterday, $closedLongAgo]);

        self::assertSame([RefundFinding::AMOUNT_MISMATCH], self::codes($changes));
        self::assertSame($closedYesterday, $changes[0]->before);

        self::assertSame([], self::match(self::payment('P', '7000000', ledger: self::CREATED + 30), [$closedLongAgo]));
    }

    public function testWhileARequestIsOpenTheClosedOnesGetNoFindingForAStrayPayment(): void
    {
        $closedYesterday = self::executed(self::refund(1, '40'), 'first', closedAt: self::NOW - 86400);
        $open = self::refund(2, '50');

        $changes = self::match(self::payment('P', '7000000', ledger: self::CREATED + 30), [$closedYesterday, $open]);

        self::assertSame([$open], array_column($changes, 'before'));
    }

    public function testAFindingIsRecordedOncePerRefundAndTransaction(): void
    {
        $refund = self::refund(1, '40', tag: 4711);
        $payment = self::payment('P', '40000000');

        $first = self::match($payment, [$refund]);
        $second = self::match($payment, [$first[0]->after]);

        self::assertCount(1, $first);
        self::assertSame([], $second, 'reading the same ledger again changes nothing');
    }

    public function testWhatHasNoDestinationOrIsNotFinalConcernsNoRefund(): void
    {
        $refund = self::refund(1, '40');

        $trustSet = self::entry('P', '40000000');
        $trustSet['tx']['TransactionType'] = 'TrustSet';
        unset($trustSet['tx']['Destination'], $trustSet['meta']['delivered_amount']);
        self::assertSame([], self::match(LedgerTransaction::fromAccountTx($trustSet), [$refund]));

        $pending = self::entry('Q', '40000000');
        $pending['validated'] = false;
        self::assertSame([], self::match(LedgerTransaction::fromAccountTx($pending), [$refund]));
    }

    /**
     * @param list<Refund> $toDestination
     * @return list<RefundChange>
     */
    private static function match(LedgerTransaction $transaction, array $toDestination, ?Refund $referenced = null): array
    {
        return (new RefundMatcher())->match($transaction, $toDestination, $referenced, self::NOW, self::NOW - self::WEEK);
    }

    /**
     * @param string|array<string, string> $delivered
     */
    private static function payment(
        string $name,
        string|array $delivered,
        ?int $tag = null,
        int $ledger = self::CREATED + 10,
        string $destination = self::CUSTOMER,
        ?string $invoiceId = null,
        string $result = 'tesSUCCESS',
    ): LedgerTransaction {
        return LedgerTransaction::fromAccountTx(self::entry($name, $delivered, $tag, $ledger, $destination, $invoiceId, $result));
    }

    /**
     * @param string|array<string, string> $delivered
     * @return array<string, mixed>
     */
    private static function entry(
        string $name,
        string|array $delivered,
        ?int $tag = null,
        int $ledger = self::CREATED + 10,
        string $destination = self::CUSTOMER,
        ?string $invoiceId = null,
        string $result = 'tesSUCCESS',
    ): array {
        $tx = [
            'Account' => self::MERCHANT,
            'Amount' => $delivered,
            'Destination' => $destination,
            'Flags' => 0,
            'TransactionType' => 'Payment',
            'date' => 848500511,
            'hash' => self::hash($name),
            'ledger_index' => $ledger,
        ];
        if ($tag !== null) {
            $tx['DestinationTag'] = $tag;
        }
        if ($invoiceId !== null) {
            $tx['InvoiceID'] = $invoiceId;
        }

        $meta = ['TransactionResult' => $result];
        if ($result === 'tesSUCCESS') {
            $meta['delivered_amount'] = $delivered;
        }

        return ['meta' => $meta, 'tx' => $tx, 'validated' => true];
    }

    /**
     * @param array{0: string, 1: string}|null $asset currency and issuer; null for the native asset
     */
    private static function refund(int $number, string $amount, ?int $tag = null, int $createdLedger = self::CREATED, ?array $asset = null): Refund
    {
        return Refund::request(
            id: str_pad(dechex($number), 32, '0', STR_PAD_LEFT),
            chain: 'XRPL',
            network: 'testnet',
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: 'order-transaction-1',
            sourceHash: self::hash('source'),
            serial: $number,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(
                self::CUSTOMER,
                $tag,
                RefundDestination::ORIGIN_SENDER,
                $tag === null ? RefundDestination::ATTESTATION_OWN_WALLET : null,
            ),
            amount: $asset === null ? LedgerAmount::native($amount) : LedgerAmount::issued($asset[0], $asset[1], $amount),
            reference: self::hash('reference-' . $number),
            createdAt: self::NOW - 3600,
            createdLedger: $createdLedger,
            createdBy: 'admin',
        );
    }

    private static function executed(Refund $refund, string $hash, int $closedAt = self::NOW - 60): Refund
    {
        return $refund->executed(self::hash($hash), self::CREATED + 10, $closedAt - 5, self::MERCHANT, $refund->amount, Refund::MATCHED_BY_KEY, $closedAt);
    }

    /**
     * @param list<RefundChange> $changes
     * @return list<?string>
     */
    private static function codes(array $changes): array
    {
        return array_map(static fn (RefundChange $change): ?string => $change->finding?->code, $changes);
    }

    private static function hash(string $name): string
    {
        return strtoupper(hash('sha256', $name));
    }
}
