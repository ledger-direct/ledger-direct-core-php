<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundFinding;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use PHPUnit\Framework\TestCase;

/**
 * The contract every RefundRepositoryInterface implementation has to keep — as a test an
 * adapter extends. Four of its rules are the only thing standing between two simultaneous
 * requests and a payment refunded or assigned twice, and they hold only as unique indexes: the
 * numbering per incoming payment, the open key, the execution hash, the reference. The core's own
 * InMemoryRefundRepository passes this suite, which is what keeps the suite honest.
 *
 * Needs PHPUnit, which the core lists under `suggest`, not `require`: this class is only ever
 * loaded by a test that extends it.
 */
abstract class RefundRepositoryContractTestCase extends TestCase
{
    protected const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    protected const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    protected const OTHER_CUSTOMER = 'rB2CxqjRjUkbebPZ6MgnXEaoSUFrmfsC9';
    protected const OTHER_MERCHANT = 'rHB5pqBMCr75eCMZgvQPRNmuEqTfbKANvf';

    /** A fresh, empty repository; called once per test. */
    abstract protected function repository(): RefundRepositoryInterface;

    /** The chain whose table the repository stores. Override for a Xahau repository. */
    protected function chain(): string
    {
        return 'XRPL';
    }

    /**
     * A refund awaiting execution. $number makes id, source payment and reference distinct;
     * give two refunds the same $source to make them refunds of one payment.
     */
    protected function refund(
        int $number,
        ?int $source = null,
        int $serial = 1,
        string $network = 'testnet',
        string $payingAccount = self::MERCHANT,
        string $destination = self::CUSTOMER,
        ?int $tag = 4711,
        string $amount = '1.07',
        int $createdAt = 1_800_000_000,
        string $platformReference = 'order-transaction-1',
    ): Refund {
        return Refund::request(
            id: str_pad(dechex($number), 32, '0', STR_PAD_LEFT),
            chain: $this->chain(),
            network: $network,
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: $platformReference,
            sourceHash: self::hash('source', $source ?? $number),
            serial: $serial,
            payingAccount: $payingAccount,
            destination: new RefundDestination(
                $destination,
                $tag,
                RefundDestination::ORIGIN_SENDER,
                $tag === null ? RefundDestination::ATTESTATION_OWN_WALLET : null,
            ),
            amount: LedgerAmount::native($amount),
            reference: self::hash('reference', $number),
            createdAt: $createdAt,
            createdLedger: 22_475_000,
            createdBy: 'admin',
        );
    }

    /** The refund, executed by a payment with the given hash number. */
    protected function executed(Refund $refund, int $hash, int $closedAt = 1_800_000_600): Refund
    {
        return $refund->executed(
            hash: self::hash('execution', $hash),
            ledgerIndex: 22_475_018,
            executedAt: $closedAt - 10,
            account: $refund->payingAccount,
            delivered: $refund->amount,
            matchedBy: Refund::MATCHED_BY_KEY,
            closedAt: $closedAt,
        );
    }

    protected static function hash(string $kind, int $number): string
    {
        return strtoupper(hash('sha256', $kind . '-' . $number));
    }

    public function testARefundComesBackAsItWasStored(): void
    {
        $repository = $this->repository();
        $issuer = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

        $refund = Refund::request(
            id: str_repeat('a', 32),
            chain: $this->chain(),
            network: 'testnet',
            purpose: Refund::PURPOSE_WRONG_ASSET_RETURN,
            platformReference: '0198f2a4c1b77d3e9a6b5c4d3e2f1a0b',
            sourceHash: self::hash('source', 1),
            serial: 3,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(self::CUSTOMER, 4294967295, RefundDestination::ORIGIN_STATED),
            amount: LedgerAmount::issued('524C555344000000000000000000000000000000', $issuer, '21.6'),
            reference: self::hash('reference', 1),
            createdAt: 1_800_000_000,
            createdLedger: 22_475_000,
            createdBy: 'Zoë <zoe@example.com>',
            reason: "Return of the wrong token\nsecond line, with „quotes“ and a \\ backslash",
            fiatAmount: '20.00',
            fiatCurrency: 'EUR',
        );

        self::assertTrue($repository->insert($refund));
        self::assertEquals($refund, $repository->find($refund->id));

        $finding = new RefundFinding(
            code: RefundFinding::FAILED_ATTEMPT,
            hash: self::hash('attempt', 1),
            ledgerIndex: 22_475_012,
            account: self::MERCHANT,
            destination: self::CUSTOMER,
            destinationTag: null,
            delivered: null,
            result: 'tecPATH_DRY',
            transactionType: 'Payment',
            recordedAt: 1_800_000_100,
        );
        $changed = $refund
            ->withFinding($finding)
            ->executed(
                hash: self::hash('execution', 1),
                ledgerIndex: 22_475_022,
                executedAt: 1_800_000_200,
                account: self::OTHER_MERCHANT,
                delivered: LedgerAmount::issued('524C555344000000000000000000000000000000', $issuer, '21.5'),
                matchedBy: Refund::MATCHED_BY_ATTACHED,
                closedAt: 1_800_000_300,
            )
            ->booked(1_800_000_400, 'booked, state unchanged');

        self::assertTrue($repository->update($changed, $refund->version));

        $stored = $repository->find($refund->id);
        self::assertEquals($changed, $stored);
        self::assertSame([Refund::DEVIATION_OTHER_ACCOUNT, Refund::DEVIATION_AMOUNT], $stored?->deviations);
        self::assertSame(4294967295, $stored?->destination->tag);
    }

    /**
     * A row does not say which chain it is from; the table does. A refund of another chain
     * stored here would read back as this chain's.
     */
    public function testARefundOfAnotherChainIsNotStoredInThisTable(): void
    {
        $repository = $this->repository();
        $otherChain = $this->chain() === 'XRPL' ? 'XAHAU' : 'XRPL';
        $foreign = Refund::request(
            id: str_repeat('d', 32),
            chain: $otherChain,
            network: 'testnet',
            purpose: Refund::PURPOSE_WRONG_ASSET_RETURN,
            platformReference: 'order-transaction-1',
            sourceHash: self::hash('source', 1),
            serial: 1,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(self::CUSTOMER, 4711, RefundDestination::ORIGIN_SENDER),
            amount: LedgerAmount::issued('USD', self::OTHER_MERCHANT, '5'),
            reference: self::hash('reference', 1),
            createdAt: 1_800_000_000,
            createdLedger: 22_475_000,
        );

        try {
            $repository->insert($foreign);
            self::fail('A refund of another chain must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertNull($repository->find($foreign->id));
        }
    }

    public function testAnUnknownIdIsNull(): void
    {
        self::assertNull($this->repository()->find(str_repeat('f', 32)));
    }

    /**
     * Two requests that counted the same refunds of one payment get the same number. The second
     * must be refused by storage: that is what keeps both from fitting under the payment's amount.
     */
    public function testTheSameNumberForOneSourcePaymentIsRefused(): void
    {
        $repository = $this->repository();

        self::assertTrue($repository->insert($this->refund(1, source: 1, serial: 1, amount: '1')));
        self::assertFalse(
            $repository->insert($this->refund(2, source: 1, serial: 1, amount: '2', destination: self::OTHER_CUSTOMER)),
            'the same source payment and serial is refused whatever else differs',
        );
        self::assertNull($repository->find($this->refund(2)->id), 'a refused refund is not stored');

        self::assertTrue($repository->insert($this->refund(3, source: 1, serial: 2, amount: '2')));
        self::assertTrue(
            $repository->insert($this->refund(4, source: 1, serial: 1, network: 'mainnet', amount: '3')),
            'the numbering is per network',
        );
    }

    public function testTheSameIdIsRefused(): void
    {
        $repository = $this->repository();

        self::assertTrue($repository->insert($this->refund(1, amount: '1')));
        self::assertFalse($repository->insert($this->refund(1, source: 9, amount: '2')));
        self::assertSame('1', $repository->find($this->refund(1)->id)?->amount->value);
    }

    /**
     * No second open refund to the same destination, tag, asset and amount, even for another
     * incoming payment: a payment on the ledger could not be told to belong to one or the other.
     */
    public function testASecondOpenRefundWithTheSameKeyIsRefusedUntilTheFirstIsClosed(): void
    {
        $repository = $this->repository();
        $first = $this->refund(1);

        self::assertTrue($repository->insert($first));
        self::assertFalse($repository->insert($this->refund(2)), 'same paying account, destination, tag, asset and amount');

        self::assertTrue($repository->insert($this->refund(3, amount: '1.08')), 'another amount is another key');
        self::assertTrue($repository->insert($this->refund(4, tag: 4712)), 'another tag is another key');
        self::assertTrue($repository->insert($this->refund(5, network: 'mainnet')), 'another network is another key');

        self::assertTrue($repository->update($first->cancelled(1_800_000_500), $first->version));
        self::assertTrue($repository->insert($this->refund(2)), 'the key is free once the first refund is closed');
    }

    public function testNoTagAndTagZeroAreDifferentKeys(): void
    {
        $repository = $this->repository();

        self::assertTrue($repository->insert($this->refund(1, tag: 0)));
        self::assertTrue($repository->insert($this->refund(2, tag: null)));

        self::assertSame(0, $repository->find($this->refund(1)->id)?->destination->tag);
        self::assertNull($repository->find($this->refund(2)->id)?->destination->tag);
    }

    public function testOnePaymentExecutesOneRefund(): void
    {
        $repository = $this->repository();
        $first = $this->refund(1, amount: '1');
        $second = $this->refund(2, amount: '2');
        $repository->insert($first);
        $repository->insert($second);

        self::assertTrue($repository->update($this->executed($first, hash: 1), $first->version));
        self::assertFalse(
            $repository->update($this->executed($second, hash: 1), $second->version),
            'the execution hash is taken',
        );
        self::assertTrue($repository->find($second->id)?->isAwaitingExecution(), 'a refused change is not stored');

        self::assertTrue($repository->update($this->executed($second, hash: 2), $second->version));
    }

    /**
     * A payment names its refund by the reference it carries, so a reference belongs to one
     * refund per network.
     */
    public function testARefundIsFoundByItsReferenceAndAReferenceIsNotGivenTwice(): void
    {
        $repository = $this->repository();
        $refund = $this->refund(1, amount: '1');
        $repository->insert($refund);
        $repository->insert($this->refund(2, amount: '2'));

        self::assertEquals($refund, $repository->findByReference('testnet', $refund->reference));
        self::assertNull($repository->findByReference('mainnet', $refund->reference), 'a reference is looked up per network');
        self::assertNull($repository->findByReference('testnet', self::hash('reference', 99)));

        $sameReference = Refund::request(
            id: str_repeat('c', 32),
            chain: $this->chain(),
            network: 'testnet',
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: 'order-transaction-1',
            sourceHash: self::hash('source', 3),
            serial: 1,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(self::OTHER_CUSTOMER, 1, RefundDestination::ORIGIN_STATED),
            amount: LedgerAmount::native('3'),
            reference: $refund->reference,
            createdAt: 1_800_000_000,
            createdLedger: 22_475_000,
            createdBy: 'admin',
        );
        self::assertFalse($repository->insert($sameReference));
    }

    /**
     * Two processes read the same refund and both change it: the second change is refused, and
     * the caller reads again.
     */
    public function testAChangeFromAStaleVersionIsRefused(): void
    {
        $repository = $this->repository();
        $refund = $this->refund(1);
        $repository->insert($refund);

        $executed = $this->executed($refund, hash: 1);
        self::assertTrue($repository->update($executed, $refund->version));
        self::assertFalse($repository->update($refund->cancelled(1_800_000_700), $refund->version));

        self::assertEquals($executed, $repository->find($refund->id));
        self::assertSame(2, $repository->find($refund->id)?->version);
    }

    public function testAChangeToARefundThatDoesNotExistIsRefused(): void
    {
        $refund = $this->refund(1);

        self::assertFalse($this->repository()->update($refund->cancelled(1_800_000_700), $refund->version));
    }

    public function testTheRefundsOfOneSourcePaymentComeBySerialAndPerNetwork(): void
    {
        $repository = $this->repository();
        $repository->insert($this->refund(1, source: 1, serial: 2, amount: '2', createdAt: 1_800_000_000));
        $repository->insert($this->refund(2, source: 1, serial: 1, amount: '1', createdAt: 1_800_000_900));
        $repository->insert($this->refund(3, source: 1, serial: 3, amount: '3', network: 'mainnet'));
        $repository->insert($this->refund(4, source: 2, serial: 1, amount: '4'));

        $refunds = $repository->findBySource('testnet', self::hash('source', 1));

        self::assertSame([1, 2], array_map(static fn (Refund $refund): int => $refund->serial, $refunds));
        self::assertSame([], $repository->findBySource('testnet', self::hash('source', 99)));
    }

    public function testTheRefundsOfAPlatformReferenceComeOldestFirst(): void
    {
        $repository = $this->repository();
        $repository->insert($this->refund(3, amount: '3', createdAt: 1_800_000_500));
        $repository->insert($this->refund(2, amount: '2', createdAt: 1_800_000_100));
        // The same second as the one above: the id decides, so the order is total.
        $repository->insert($this->refund(1, amount: '1', createdAt: 1_800_000_100));
        $repository->insert($this->refund(4, amount: '4', createdAt: 1_800_000_000, platformReference: 'order-transaction-2'));

        self::assertSame(
            ['1', '2', '3'],
            self::amounts($repository->findByPlatformReference('order-transaction-1')),
        );
        self::assertSame([], $repository->findByPlatformReference('order-transaction-9'));
    }

    /**
     * Ids are text. Two ids that look like numbers ("0e…1" is zero to a careless comparison)
     * are still two ids, ordered as text.
     */
    public function testATieInTimeIsBrokenByTheIdAsText(): void
    {
        $repository = $this->repository();
        $withId = fn (string $id, int $number): Refund => Refund::request(
            id: $id,
            chain: $this->chain(),
            network: 'testnet',
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: 'order-transaction-1',
            sourceHash: self::hash('source', $number),
            serial: 1,
            payingAccount: self::MERCHANT,
            destination: new RefundDestination(self::CUSTOMER, $number, RefundDestination::ORIGIN_SENDER),
            amount: LedgerAmount::native((string) $number),
            reference: self::hash('reference', $number),
            createdAt: 1_800_000_000,
            createdLedger: 22_475_000,
        );
        $repository->insert($withId('0e' . str_repeat('0', 29) . '1', 2));
        $repository->insert($withId(str_repeat('0', 32), 1));

        self::assertSame(['1', '2'], self::amounts($repository->findByPlatformReference('order-transaction-1')));
    }

    public function testTheRefundsToADestinationAreScopedByNetworkAndPayingAccount(): void
    {
        $repository = $this->repository();
        $open = $this->refund(1, amount: '1', createdAt: 1_800_000_100);
        $closed = $this->refund(2, amount: '2', tag: null, createdAt: 1_800_000_200);
        $repository->insert($open);
        $repository->insert($closed);
        $repository->update($closed->cancelled(1_800_000_300), $closed->version);
        $repository->insert($this->refund(3, amount: '3', destination: self::OTHER_CUSTOMER));
        $repository->insert($this->refund(4, amount: '4', payingAccount: self::OTHER_MERCHANT));
        $repository->insert($this->refund(5, amount: '5', network: 'mainnet'));

        self::assertSame(
            ['1', '2'],
            self::amounts($repository->findByDestination('testnet', self::MERCHANT, self::CUSTOMER)),
            'every status and every tag, oldest first',
        );
        self::assertSame([], $repository->findByDestination('testnet', self::MERCHANT, self::OTHER_MERCHANT));
    }

    /**
     * Watched: awaiting execution, or closed at or after the given time.
     */
    public function testWatchedAreTheOpenRefundsAndThoseClosedSince(): void
    {
        $repository = $this->repository();
        $open = $this->refund(1, amount: '1', createdAt: 1_800_000_100);
        $closedLongAgo = $this->refund(2, amount: '2', createdAt: 1_800_000_200);
        $closedRecently = $this->refund(3, amount: '3', createdAt: 1_800_000_300);
        $closedExactlyThen = $this->refund(4, amount: '4', createdAt: 1_800_000_400);
        foreach ([$open, $closedLongAgo, $closedRecently, $closedExactlyThen] as $refund) {
            $repository->insert($refund);
        }
        $repository->update($closedLongAgo->cancelled(1_800_001_000), $closedLongAgo->version);
        $repository->update($this->executed($closedRecently, hash: 3, closedAt: 1_800_009_000), $closedRecently->version);
        $repository->update($closedExactlyThen->cancelled(1_800_005_000), $closedExactlyThen->version);

        $otherAccountOpen = $this->refund(5, amount: '5', payingAccount: self::OTHER_MERCHANT);
        $otherAccountClosed = $this->refund(6, amount: '6', payingAccount: self::OTHER_CUSTOMER, destination: self::CUSTOMER);
        $mainnetOpen = $this->refund(7, amount: '7', network: 'mainnet', payingAccount: self::CUSTOMER, destination: self::MERCHANT);
        foreach ([$otherAccountOpen, $otherAccountClosed, $mainnetOpen] as $refund) {
            $repository->insert($refund);
        }
        $repository->update($otherAccountClosed->cancelled(1_800_001_000), $otherAccountClosed->version);

        self::assertSame(
            ['1', '3', '4'],
            self::amounts($repository->findWatched('testnet', self::MERCHANT, 1_800_005_000)),
        );

        $accounts = $repository->watchedAccounts('testnet', 1_800_005_000);
        sort($accounts);
        $expected = [self::MERCHANT, self::OTHER_MERCHANT];
        sort($expected);
        self::assertSame($expected, $accounts, 'each account once; not the one whose only refund closed long ago');

        self::assertSame([self::CUSTOMER], $repository->watchedAccounts('mainnet', 1_800_005_000));
        self::assertSame([], $repository->findWatched('mainnet', self::MERCHANT, 0));
    }

    /**
     * @param list<Refund> $refunds
     * @return list<string>
     */
    private static function amounts(array $refunds): array
    {
        return array_map(static fn (Refund $refund): string => $refund->amount->value, $refunds);
    }
}
