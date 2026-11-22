<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Refund;

use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundFinding;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundTest extends TestCase
{
    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const OTHER_ACCOUNT = 'rHB5pqBMCr75eCMZgvQPRNmuEqTfbKANvf';
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    public function testANewRequestAwaitsExecutionAtVersionOne(): void
    {
        $refund = self::refund();

        self::assertSame(Refund::SCHEMA_VERSION, $refund->schemaVersion);
        self::assertTrue($refund->isAwaitingExecution());
        self::assertFalse($refund->isExecuted());
        self::assertFalse($refund->isCancelled());
        self::assertFalse($refund->isBooked());
        self::assertSame(1, $refund->version);
        self::assertNull($refund->closedAt);
        self::assertSame([], $refund->findings);
        self::assertSame('1.07', $refund->claimedAmount()?->value);
    }

    public function testTheRowHasExactlyTheDeclaredColumnsInOrder(): void
    {
        self::assertSame(Refund::COLUMNS, array_keys(self::refund()->toRow()));
    }

    public function testTheRowReadsAtAGlance(): void
    {
        $row = self::refund()->toRow();

        self::assertSame('awaiting_execution', $row['status']);
        self::assertSame('order_refund', $row['purpose']);
        self::assertSame('sender', $row['destination_origin']);
        self::assertSame('', $row['deviation']);
        self::assertNull($row['matched_by']);
        self::assertSame('XRP', $row['asset_currency']);
        self::assertNull($row['asset_issuer']);
        self::assertSame('1.07', $row['amount']);
        self::assertSame(4711, $row['destination_tag']);
        self::assertSame('[]', $row['findings']);
        self::assertSame(64, strlen((string) $row['open_key']));

        $executed = self::refund()->cancelled(200)->executed(
            hash: self::hash('execution'),
            ledgerIndex: 22_475_030,
            executedAt: 300,
            account: self::OTHER_ACCOUNT,
            delivered: LedgerAmount::native('1.06'),
            matchedBy: Refund::MATCHED_BY_ATTACHED,
            closedAt: 400,
        )->toRow();

        self::assertSame('executed', $executed['status']);
        self::assertSame('attached', $executed['matched_by']);
        self::assertSame('other_account,amount,after_cancel', $executed['deviation']);
        self::assertSame(self::MERCHANT, $executed['paying_account']);
        self::assertSame(self::OTHER_ACCOUNT, $executed['executed_account']);
        self::assertSame('1.07', $executed['amount']);
        self::assertSame('1.06', $executed['delivered_amount']);
        self::assertNull($executed['open_key'], 'a closed refund lets its key go');
    }

    /**
     * @param callable(): Refund $build
     */
    #[DataProvider('refundsInEveryState')]
    public function testARefundSurvivesTheRowAsADatabaseReturnsIt(callable $build, string $chain): void
    {
        $refund = $build();

        self::assertEquals($refund, Refund::fromRow($refund->toRow(), $chain));

        $fromDriver = array_map(
            static fn (string|int|null $value): ?string => $value === null ? null : (string) $value,
            $refund->toRow(),
        );
        self::assertEquals($refund, Refund::fromRow($fromDriver + ['a_column_the_platform_added' => 'x'], $chain));
    }

    /**
     * @return array<string, array{callable(): Refund, string}>
     */
    public static function refundsInEveryState(): array
    {
        return [
            'awaiting execution, native' => [static fn (): Refund => self::refund(), 'XRPL'],
            'awaiting execution on Xahau' => [static fn (): Refund => self::refund(chain: 'XAHAU'), 'XAHAU'],
            'without a tag, attested' => [static fn (): Refund => self::refund(tag: null), 'XRPL'],
            'tag zero' => [static fn (): Refund => self::refund(tag: 0), 'XRPL'],
            'a token with a fiat amount' => [
                static fn (): Refund => self::refund(
                    amount: LedgerAmount::issued(self::RLUSD, self::ISSUER, '21.6'),
                    fiatAmount: '20.00',
                    fiatCurrency: 'EUR',
                ),
                'XRPL',
            ],
            'cancelled' => [static fn (): Refund => self::refund()->cancelled(200), 'XRPL'],
            'executed and booked, with a finding' => [
                static fn (): Refund => self::executed(self::refund()->withFinding(self::finding()))->booked(500, 'refunded'),
                'XRPL',
            ],
            'executed with a booking claim' => [static fn (): Refund => self::executed(self::refund())->withBookingClaim(900), 'XRPL'],
        ];
    }

    /**
     * @param callable(array<string, string|int|null>): array<string, mixed> $damage
     */
    #[DataProvider('damagedRows')]
    public function testADamagedRowIsRefusedNotHalfRead(callable $damage): void
    {
        $row = self::executed(self::refund()->withFinding(self::finding()))->toRow();

        $this->expectException(InvalidArgumentException::class);

        Refund::fromRow($damage($row), 'XRPL');
    }

    /**
     * @return array<string, array{callable(array<string, string|int|null>): array<string, mixed>}>
     */
    public static function damagedRows(): array
    {
        $set = static fn (string $column, mixed $value): callable => static function (array $row) use ($column, $value): array {
            $row[$column] = $value;

            return $row;
        };

        return [
            'a missing column' => [static function (array $row): array {
                unset($row['reference']);

                return $row;
            }],
            'a schema version from the future' => [$set('schema_version', 2)],
            'an unknown status' => [$set('status', 'paid')],
            'an unknown purpose' => [$set('purpose', 'gift')],
            'an unknown origin' => [$set('destination_origin', 'guessed')],
            'an unknown way of matching' => [$set('matched_by', 'hunch')],
            'an unknown deviation' => [$set('deviation', 'amount,colour')],
            'deviations out of order' => [$set('deviation', 'amount,other_account')],
            'a negative integer' => [$set('serial', '-1')],
            'a fraction where an integer belongs' => [$set('version', '1.5')],
            'an integer with leading zeros' => [$set('created_ledger', '0022475000')],
            'an integer column holding text' => [$set('created_at', 'yesterday')],
            'a tag beyond 32 bits' => [$set('destination_tag', '4294967296')],
            'findings that are not JSON' => [$set('findings', '[{')],
            'findings that are not a list' => [$set('findings', '{"code":"duplicate"}')],
            'a finding with an unknown code' => [$set('findings', '[{"code":"odd","hash":"' . str_repeat('A', 64) . '","ledger_index":1,"account":"r","destination":"r","destination_tag":null,"delivered":null,"result":"tesSUCCESS","transaction_type":"Payment","recorded_at":1}]')],
            'a finding missing a field' => [$set('findings', '[{"code":"duplicate"}]')],
            'an amount that is not a number' => [$set('amount', 'much')],
            'a token named without its issuer' => [$set('asset_currency', 'USD')],
            'an executed refund without its hash' => [$set('executed_hash', null)],
            'a status that contradicts the execution' => [$set('status', 'cancelled')],
            'a null where a value belongs' => [$set('network', null)],
            'findings as a JSON object' => [$set('findings', '{}')],
            'an integer with a trailing newline' => [$set('version', "2\n")],
            'an execution hash with a trailing newline' => [$set('executed_hash', self::hash('execution') . "\n")],
            // The stored key is what the unique index holds; it has to be the refund's own.
            'an open key on a refund that is closed' => [$set('open_key', str_repeat('a', 64))],
            'an execution found by the core in the ledger the refund was created in' => [$set('executed_ledger', '22475000')],
            'a negative time' => [$set('created_at', -1)],
            'a fiat amount that is not a number' => [static function (array $row): array {
                $row['fiat_amount'] = 'twenty';
                $row['fiat_currency'] = 'EUR';

                return $row;
            }],
        ];
    }

    /**
     * An open refund's key is derived, but it is the stored value the unique index holds: a row
     * without it reserves nothing, and a second refund with the same key could sit next to it.
     */
    public function testAnOpenRefundWhoseRowDoesNotHoldItsKeyIsRefused(): void
    {
        $row = self::refund()->toRow();

        foreach ([null, str_repeat('a', 64)] as $stored) {
            try {
                Refund::fromRow(['open_key' => $stored] + $row, 'XRPL');
                self::fail('An open refund must reserve its own key.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('open_key', $exception->getMessage());
            }
        }
    }

    public function testXrpInTheXahauTableIsNotTheNativeAsset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Refund::fromRow(self::refund()->toRow(), 'XAHAU');
    }

    /**
     * An account at an exchange is shared and told apart by the tag: without one the refund
     * arrives and belongs to nobody. So "no tag" exists only with an attestation.
     */
    public function testADestinationWithoutATagNeedsAnAttestation(): void
    {
        $attested = new RefundDestination(self::CUSTOMER, null, RefundDestination::ORIGIN_SENDER, RefundDestination::ATTESTATION_OWN_WALLET);
        self::assertNull($attested->tag);
        self::assertSame(['account' => self::CUSTOMER, 'tag' => null, 'origin' => 'sender', 'attestation' => 'own_wallet'], $attested->toArray());

        $this->expectException(InvalidArgumentException::class);

        new RefundDestination(self::CUSTOMER, null, RefundDestination::ORIGIN_SENDER);
    }

    public function testTagZeroIsATagAndNeedsNoAttestation(): void
    {
        $destination = new RefundDestination(self::CUSTOMER, 0, RefundDestination::ORIGIN_STATED);

        self::assertSame(0, $destination->tag);
        self::assertSame('0', $destination->toArray()['tag']);
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('requestsThatCannotExist')]
    public function testARequestThatMakesNoSenseCannotBeBuilt(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function requestsThatCannotExist(): array
    {
        return [
            'to the account that pays it' => [static fn (): Refund => self::refund(destination: self::MERCHANT)],
            "to the token's issuer" => [static fn (): Refund => self::refund(
                destination: self::ISSUER,
                amount: LedgerAmount::issued(self::RLUSD, self::ISSUER, '1'),
            )],
            'of nothing' => [static fn (): Refund => self::refund(amount: LedgerAmount::native('0'))],
            'with an unknown purpose' => [static fn (): Refund => self::refund(purpose: 'gift')],
            'on a chain without refunds' => [static fn (): Refund => self::refund(chain: 'STELLAR')],
            'with a fiat amount and no currency' => [static fn (): Refund => self::refund(fiatAmount: '20.00')],
            'with an id that is not one' => [static fn (): Refund => self::refund(id: 'refund-1')],
            'with a source hash in lower case' => [static fn (): Refund => self::refund(sourceHash: strtolower(self::hash('source')))],
            'with serial zero' => [static fn (): Refund => self::refund(serial: 0)],
            'without a platform reference' => [static fn (): Refund => self::refund(platformReference: '')],
            'to a stated destination, created by nobody' => [static fn (): Refund => Refund::request(
                id: '0123456789abcdef0123456789abcdef',
                chain: 'XRPL',
                network: 'testnet',
                purpose: Refund::PURPOSE_ORDER_REFUND,
                platformReference: 'order-transaction-1',
                sourceHash: self::hash('source'),
                serial: 1,
                payingAccount: self::MERCHANT,
                destination: new RefundDestination(self::CUSTOMER, 4711, RefundDestination::ORIGIN_STATED),
                amount: LedgerAmount::native('1'),
                reference: self::hash('reference'),
                createdAt: 100,
                createdLedger: 22_475_000,
            )],
            'without a tag, vouched for by nobody' => [static fn (): Refund => Refund::request(
                id: '0123456789abcdef0123456789abcdef',
                chain: 'XRPL',
                network: 'testnet',
                purpose: Refund::PURPOSE_ORDER_REFUND,
                platformReference: 'order-transaction-1',
                sourceHash: self::hash('source'),
                serial: 1,
                payingAccount: self::MERCHANT,
                destination: new RefundDestination(self::CUSTOMER, null, RefundDestination::ORIGIN_SENDER, RefundDestination::ATTESTATION_OWN_WALLET),
                amount: LedgerAmount::native('1'),
                reference: self::hash('reference'),
                createdAt: 100,
                createdLedger: 22_475_000,
                createdBy: '  ',
            )],
            'with an id followed by a newline' => [static fn (): Refund => self::refund(id: "0123456789abcdef0123456789abcdef\n")],
            'with a source hash followed by a newline' => [static fn (): Refund => self::refund(sourceHash: self::hash('source') . "\n")],
            'with a fiat amount that is not a number' => [static fn (): Refund => self::refund(fiatAmount: 'twenty', fiatCurrency: 'EUR')],
            'with a fiat amount of nothing' => [static fn (): Refund => self::refund(fiatAmount: '0', fiatCurrency: 'EUR')],
            'with a fiat amount in no currency' => [static fn (): Refund => self::refund(fiatAmount: '20.00', fiatCurrency: '')],
            'with a tag beyond 32 bits' => [static fn (): RefundDestination => new RefundDestination(self::CUSTOMER, 4294967296, RefundDestination::ORIGIN_SENDER)],
            'with a negative tag' => [static fn (): RefundDestination => new RefundDestination(self::CUSTOMER, -1, RefundDestination::ORIGIN_SENDER)],
            'with an unknown attestation' => [static fn (): RefundDestination => new RefundDestination(self::CUSTOMER, null, RefundDestination::ORIGIN_SENDER, 'probably')],
        ];
    }

    public function testAnExactPaymentFromThePayingAccountExecutesWithoutDeviation(): void
    {
        $refund = self::refund();
        $executed = self::executed($refund);

        self::assertTrue($executed->isExecuted());
        self::assertSame([], $executed->deviations);
        self::assertSame(2, $executed->version);
        self::assertSame(1, $refund->version, 'the request itself is unchanged');
        self::assertNull($executed->openKey());
        self::assertSame('1.07', $executed->claimedAmount()?->value);
        self::assertSame(400, $executed->closedAt);
    }

    public function testWhatDiffersFromTheRequestIsWrittenDown(): void
    {
        $refund = self::refund();

        $otherAccount = $refund->executed(self::hash('a'), 22_475_030, 300, self::OTHER_ACCOUNT, LedgerAmount::native('1.07'), Refund::MATCHED_BY_ATTACHED, 400);
        self::assertSame([Refund::DEVIATION_OTHER_ACCOUNT], $otherAccount->deviations);

        $otherAmount = $refund->executed(self::hash('b'), 22_475_030, 300, self::MERCHANT, LedgerAmount::native('1.070001'), Refund::MATCHED_BY_ATTACHED, 400);
        self::assertSame([Refund::DEVIATION_AMOUNT], $otherAmount->deviations);
        self::assertSame('1.070001', $otherAmount->claimedAmount()?->value, 'what arrived counts, not what was asked');

        $afterCancel = $refund->cancelled(200)->executed(self::hash('c'), 22_475_030, 300, self::MERCHANT, LedgerAmount::native('1.07'), Refund::MATCHED_BY_ATTACHED, 400);
        self::assertSame([Refund::DEVIATION_AFTER_CANCEL], $afterCancel->deviations);
        self::assertTrue($afterCancel->isExecuted());
    }

    /**
     * A refund is created after its ledger was validated: a payment in that ledger, or before
     * it, was made without the request. The core never takes it for the refund; a person can
     * say it was (the merchant names the transaction).
     */
    public function testAPaymentFromBeforeTheRefundExecutesItOnlyWhenTheMerchantNamesIt(): void
    {
        $refund = self::refund();
        $inTheCreationLedger = static fn (string $matchedBy): Refund => $refund->executed(
            self::hash('execution'),
            $refund->createdLedger,
            300,
            self::MERCHANT,
            $refund->amount,
            $matchedBy,
            400,
        );

        self::assertTrue($inTheCreationLedger(Refund::MATCHED_BY_ATTACHED)->isExecuted());

        foreach ([Refund::MATCHED_BY_KEY, Refund::MATCHED_BY_REFERENCE] as $byTheCore) {
            try {
                $inTheCreationLedger($byTheCore);
                self::fail('The core does not conclude that an earlier payment paid the refund.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testATimeBeforeTheEpochCannotBeBuiltBecauseARowCannotHoldIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::refund()->cancelled(-1);
    }

    public function testAPaymentInAnotherAssetIsNotTheExecution(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::refund()->executed(
            self::hash('execution'),
            22_475_030,
            300,
            self::MERCHANT,
            LedgerAmount::issued(self::RLUSD, self::ISSUER, '1.07'),
            Refund::MATCHED_BY_ATTACHED,
            400,
        );
    }

    public function testAnExecutedRefundIsNotExecutedAgain(): void
    {
        $this->expectException(LogicException::class);

        self::executed(self::executed(self::refund()));
    }

    public function testOnlyAnOpenRequestIsCancelledAndACancelledOneClaimsNothing(): void
    {
        $cancelled = self::refund()->cancelled(200);

        self::assertTrue($cancelled->isCancelled());
        self::assertNull($cancelled->claimedAmount());
        self::assertNull($cancelled->openKey());
        self::assertSame(200, $cancelled->closedAt);

        $this->expectException(LogicException::class);

        self::executed(self::refund())->cancelled(500);
    }

    public function testThereIsOneFindingPerTransaction(): void
    {
        $refund = self::refund()->withFinding(self::finding());

        self::assertCount(1, $refund->findings);
        self::assertSame(2, $refund->version);
        self::assertTrue($refund->hasFindingFor(self::hash('attempt')));

        $again = $refund->withFinding(self::finding(RefundFinding::TAG_MISMATCH));
        self::assertSame($refund, $again, 'a second finding for the same transaction changes nothing');

        $other = $refund->withFinding(self::finding(RefundFinding::TAG_MISMATCH, 'other'));
        self::assertCount(2, $other->findings);
    }

    public function testTheExecutingTransactionIsNotAlsoAFinding(): void
    {
        $executed = self::executed(self::refund());

        self::assertSame($executed, $executed->withFinding(self::finding(RefundFinding::DUPLICATE, 'execution')));
    }

    public function testOnlyAnExecutedRefundIsBookedAndOnlyOnce(): void
    {
        $executed = self::executed(self::refund());
        $claimed = $executed->withBookingClaim(900);
        $booked = $claimed->booked(500, 'refunded partially');

        self::assertSame(900, $claimed->bookingClaimedUntil);
        self::assertFalse($claimed->isBooked());
        self::assertTrue($booked->isBooked());
        self::assertNull($booked->bookingClaimedUntil, 'a booked refund holds no claim');
        self::assertSame('refunded partially', $booked->bookingNote);

        foreach ([
            static fn (): Refund => self::refund()->withBookingClaim(900),
            static fn (): Refund => self::refund()->booked(500),
            static fn (): Refund => self::refund()->cancelled(200)->booked(500),
            static fn (): Refund => $booked->booked(600),
            static fn (): Refund => $booked->withBookingClaim(900),
        ] as $notBookable) {
            try {
                $notBookable();
                self::fail('Only an executed refund that is not booked yet can be claimed or booked.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheOpenKeyTellsTagsAmountsAssetsAndAccountsApart(): void
    {
        $key = self::refund()->openKey();

        self::assertSame($key, self::refund(id: str_repeat('b', 32), sourceHash: self::hash('another source'))->openKey());

        foreach ([
            'no tag' => self::refund(tag: null),
            'tag zero' => self::refund(tag: 0),
            'another tag' => self::refund(tag: 4712),
            'another amount' => self::refund(amount: LedgerAmount::native('1.070001')),
            'a token of the same value' => self::refund(amount: LedgerAmount::issued(self::RLUSD, self::ISSUER, '1.07')),
            'another destination' => self::refund(destination: self::OTHER_ACCOUNT),
            'another paying account' => self::refund(payingAccount: self::OTHER_ACCOUNT),
            'another network' => self::refund(network: 'mainnet'),
        ] as $difference => $refund) {
            self::assertNotSame($key, $refund->openKey(), $difference);
        }

        self::assertNotSame(self::refund(tag: null)->openKey(), self::refund(tag: 0)->openKey(), 'no tag is not tag 0');
    }

    public function testTheArrayForAStatusAnswer(): void
    {
        $array = self::executed(self::refund()->withFinding(self::finding()))->toArray();

        self::assertSame(
            [
                'schema_version', 'id', 'chain', 'network', 'status', 'purpose', 'platform_reference', 'source_hash',
                'serial', 'paying_account', 'destination', 'asset', 'amount', 'fiat_amount', 'fiat_currency',
                'reference', 'reason', 'created_at', 'created_by', 'created_ledger', 'execution', 'closed_at',
                'booking', 'findings', 'version',
            ],
            array_keys($array),
        );
        self::assertSame(['account' => self::CUSTOMER, 'tag' => '4711', 'origin' => 'sender', 'attestation' => null], $array['destination']);
        self::assertSame(['native' => true, 'currency' => 'XRP', 'issuer' => null], $array['asset']);
        self::assertSame(
            [
                'hash' => self::hash('execution'),
                'ledger_index' => 22_475_030,
                'executed_at' => 300,
                'account' => self::MERCHANT,
                'delivered_amount' => '1.07',
                'matched_by' => 'key',
                'deviations' => [],
            ],
            $array['execution'],
        );
        self::assertSame('failed_attempt', $array['findings'][0]['code']);
        self::assertNull(self::refund()->toArray()['execution']);
    }

    public function testAFindingSurvivesItsArray(): void
    {
        $finding = new RefundFinding(
            code: RefundFinding::AMOUNT_MISMATCH,
            hash: self::hash('finding'),
            ledgerIndex: 22_475_022,
            account: self::MERCHANT,
            destination: self::CUSTOMER,
            destinationTag: 0,
            delivered: LedgerAmount::issued(self::RLUSD, self::ISSUER, '1'),
            result: 'tesSUCCESS',
            transactionType: 'Payment',
            recordedAt: 1_800_000_000,
        );

        $decoded = json_decode((string) json_encode($finding->toArray()), true);

        self::assertEquals($finding, RefundFinding::fromArray($decoded));
        self::assertSame('0', $decoded['destination_tag']);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $damage
     */
    #[DataProvider('damagedFindings')]
    public function testADamagedFindingIsRefused(callable $damage): void
    {
        $this->expectException(InvalidArgumentException::class);

        RefundFinding::fromArray($damage(self::finding()->toArray()));
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function damagedFindings(): array
    {
        $set = static fn (string $field, mixed $value): callable => static function (array $finding) use ($field, $value): array {
            $finding[$field] = $value;

            return $finding;
        };

        return [
            'a tag beyond 32 bits' => [$set('destination_tag', '4294967296')],
            'a tag that is not a number' => [$set('destination_tag', '47a1')],
            'a hash followed by a newline' => [$set('hash', self::hash('attempt') . "\n")],
            // Half of the pair is a damaged record, not a native amount.
            'an issuer without a currency' => [$set('delivered', ['value' => '21.5', 'currency' => null, 'issuer' => self::ISSUER])],
            'a currency without an issuer' => [$set('delivered', ['value' => '21.5', 'currency' => 'USD', 'issuer' => null])],
            'a negative ledger index' => [$set('ledger_index', -1)],
            'an unknown code' => [$set('code', 'odd')],
        ];
    }

    public function testAFindingsTagIsAnUnsigned32BitIntegerOrNone(): void
    {
        self::assertSame(4294967295, RefundFinding::fromArray(['destination_tag' => '4294967295'] + self::finding()->toArray())->destinationTag);
        self::assertSame(0, RefundFinding::fromArray(['destination_tag' => '0'] + self::finding()->toArray())->destinationTag);
        self::assertNull(RefundFinding::fromArray(self::finding()->toArray())->destinationTag);

        $this->expectException(InvalidArgumentException::class);

        new RefundFinding(RefundFinding::TAG_MISMATCH, self::hash('x'), 1, self::MERCHANT, self::CUSTOMER, -1, null, 'tesSUCCESS', 'Payment', 1);
    }

    private static function refund(
        string $id = '0123456789abcdef0123456789abcdef',
        string $chain = 'XRPL',
        string $network = 'testnet',
        string $purpose = Refund::PURPOSE_ORDER_REFUND,
        string $platformReference = 'order-transaction-1',
        ?string $sourceHash = null,
        int $serial = 1,
        string $payingAccount = self::MERCHANT,
        string $destination = self::CUSTOMER,
        ?int $tag = 4711,
        ?LedgerAmount $amount = null,
        ?string $fiatAmount = null,
        ?string $fiatCurrency = null,
    ): Refund {
        return Refund::request(
            id: $id,
            chain: $chain,
            network: $network,
            purpose: $purpose,
            platformReference: $platformReference,
            sourceHash: $sourceHash ?? self::hash('source'),
            serial: $serial,
            payingAccount: $payingAccount,
            destination: new RefundDestination(
                $destination,
                $tag,
                RefundDestination::ORIGIN_SENDER,
                $tag === null ? RefundDestination::ATTESTATION_OWN_WALLET : null,
            ),
            amount: $amount ?? LedgerAmount::native('1.07'),
            reference: self::hash('reference'),
            createdAt: 100,
            createdLedger: 22_475_000,
            createdBy: 'admin',
            reason: 'Customer returned the goods',
            fiatAmount: $fiatAmount,
            fiatCurrency: $fiatCurrency,
        );
    }

    private static function executed(Refund $refund): Refund
    {
        return $refund->executed(
            hash: self::hash('execution'),
            ledgerIndex: 22_475_030,
            executedAt: 300,
            account: $refund->payingAccount,
            delivered: $refund->amount,
            matchedBy: Refund::MATCHED_BY_KEY,
            closedAt: 400,
        );
    }

    private static function finding(string $code = RefundFinding::FAILED_ATTEMPT, string $hash = 'attempt'): RefundFinding
    {
        return new RefundFinding(
            code: $code,
            hash: self::hash($hash),
            ledgerIndex: 22_475_016,
            account: self::MERCHANT,
            destination: self::CUSTOMER,
            destinationTag: null,
            delivered: null,
            result: 'tecDST_TAG_NEEDED',
            transactionType: 'Payment',
            recordedAt: 150,
        );
    }

    private static function hash(string $seed): string
    {
        return strtoupper(hash('sha256', $seed));
    }
}
