<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerTransaction;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads answers recorded on the XRPL testnet on 2026-11-20, unchanged:
 *
 * - `account-tx-merchant.json`: an account that received payments and paid some back, from its
 *   own side — incoming, its own TrustSet, a payment that arrived through the ledger's exchange,
 *   two refused outgoing payments (no trust line, no tag), one delivered with a tag and one
 *   partial payment;
 * - `account-tx-wallet-scans.json`: five payments Xaman signed from payment-request links, from
 *   the paying account's side — without and with a tag, a token, and two with an `InvoiceID`;
 * - `tx-failed-tag-needed.json`, `tx-incoming-pathed.json`: two of the above as `tx` answers.
 */
final class LedgerTransactionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/refund/';

    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const RLUSD = '524C555344000000000000000000000000000000';

    public function testAnIncomingPaymentIsReadWithWhatItDelivered(): void
    {
        $transaction = self::merchantList()[0];

        self::assertSame('82320DEDD4A64EAE201939E6A9D805F7912C5C0E0C76E686E86F1383DBF2F7F2', $transaction->hash);
        self::assertSame(22475002, $transaction->ledgerIndex);
        self::assertSame(848500490, $transaction->date);
        self::assertTrue($transaction->validated);
        self::assertTrue($transaction->succeeded());
        self::assertFalse($transaction->failed());
        self::assertTrue($transaction->isPayment());
        self::assertSame(self::MERCHANT, $transaction->destination);
        self::assertNull($transaction->destinationTag);
        self::assertNull($transaction->sourceTag);
        self::assertNull($transaction->invoiceId);
        self::assertTrue($transaction->delivered?->isNative());
        self::assertSame('6', $transaction->delivered?->value);
        self::assertFalse($transaction->isCrossCurrency());
    }

    public function testATransactionOfAnotherKindHasNoDestinationAndDeliveredNothing(): void
    {
        $trustSet = self::merchantList()[1];

        self::assertSame('TrustSet', $trustSet->type);
        self::assertFalse($trustSet->isPayment());
        self::assertTrue($trustSet->succeeded());
        self::assertNull($trustSet->destination);
        self::assertNull($trustSet->delivered);
        // TrustSet's tfSetNoRipple has the same bit as Payment's tfPartialPayment.
        self::assertSame(LedgerTransaction::TF_PARTIAL_PAYMENT, $trustSet->flags);
        self::assertFalse($trustSet->isPartialPayment());
    }

    /**
     * The customer spent XRP, the merchant received RLUSD: the refund in the received asset
     * would need a trust line the customer may not have.
     */
    public function testAPaymentThroughTheExchangeIsRecognisedByASendMaxInAnotherAsset(): void
    {
        $pathed = self::merchantList()[3];

        self::assertSame(self::CUSTOMER, $pathed->account);
        self::assertSame(31337, $pathed->destinationTag);
        self::assertTrue($pathed->sendMax?->isNative());
        self::assertSame('6', $pathed->sendMax?->value);
        self::assertSame(self::RLUSD, $pathed->delivered?->currency);
        self::assertSame('0.2', $pathed->delivered?->value);
        self::assertTrue($pathed->isCrossCurrency());
    }

    /**
     * Xaman states a SendMax on an ordinary token payment, in the asset it delivers.
     */
    public function testASendMaxInTheDeliveredAssetIsNotAPaymentThroughTheExchange(): void
    {
        $token = self::walletScans()[2];

        self::assertSame('1.03', $token->sendMax?->value);
        self::assertSame('1.03', $token->delivered?->value);
        self::assertTrue($token->sendMax?->sameAsset($token->delivered));
        self::assertFalse($token->isCrossCurrency());
    }

    /**
     * A SendMax with the sender as issuer means "any issuer the sender can use": in the
     * delivered currency it is not another asset, in another currency it is.
     */
    public function testASendMaxWithTheSenderAsIssuerNamesNoIssuer(): void
    {
        $entry = self::entries('account-tx-wallet-scans.json')[2];
        $sender = $entry['tx']['Account'];

        $entry['tx']['SendMax'] = ['currency' => self::RLUSD, 'issuer' => $sender, 'value' => '1.03'];
        self::assertFalse(LedgerTransaction::fromAccountTx($entry)->isCrossCurrency());

        $entry['tx']['SendMax'] = ['currency' => 'EUR', 'issuer' => $sender, 'value' => '1'];
        self::assertTrue(LedgerTransaction::fromAccountTx($entry)->isCrossCurrency());

        $entry['tx']['SendMax'] = ['currency' => self::RLUSD, 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', 'value' => '1.03'];
        self::assertTrue(LedgerTransaction::fromAccountTx($entry)->isCrossCurrency(), 'the same code from another, named issuer is another asset');
    }

    #[DataProvider('refusedPayments')]
    public function testARefusedPaymentIsFinalFailedAndDeliveredNothing(int $index, string $result): void
    {
        $refused = self::merchantList()[$index];

        self::assertSame(self::MERCHANT, $refused->account);
        self::assertSame(self::CUSTOMER, $refused->destination);
        self::assertTrue($refused->validated);
        self::assertSame($result, $refused->result);
        self::assertTrue($refused->failed());
        self::assertFalse($refused->succeeded());
        self::assertNull($refused->delivered);
        self::assertNull($refused->destinationTag);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusedPayments(): array
    {
        return [
            'a token to an account without the trust line' => [4, 'tecPATH_DRY'],
            'no tag to an account that requires one' => [5, 'tecDST_TAG_NEEDED'],
        ];
    }

    public function testAPaymentWithATagCarriesItAndAnExactNativeAmount(): void
    {
        $tagged = self::merchantList()[6];

        self::assertSame(4711, $tagged->destinationTag);
        self::assertTrue($tagged->succeeded());
        self::assertSame('1.07', $tagged->delivered?->value);
    }

    /**
     * `Amount` said 5, `SendMax` 1 with tfPartialPayment: 1 arrived. Only the delivered amount counts.
     */
    public function testAPartialPaymentDeliveredLessThanItsAmountField(): void
    {
        $partial = self::merchantList()[7];

        self::assertTrue($partial->isPartialPayment());
        self::assertTrue($partial->succeeded());
        self::assertSame('1', $partial->delivered?->value);
        self::assertSame(self::RLUSD, $partial->delivered?->currency);
    }

    public function testTheInvoiceIdOfAPaymentRequestLinkIsOnThePayment(): void
    {
        $scans = self::walletScans();

        self::assertNull($scans[0]->invoiceId);
        self::assertSame('5D4BEC5D69EB53BB0384945D4572489CAFCB75C69CD6DBFC681D3FC0677D4774', $scans[3]->invoiceId);
        self::assertNull($scans[3]->destinationTag);
        self::assertSame('0A8D6E86103A268FFEFAD23FE9F01B2E81CD89899AD129D99C28AD9D7C7D83E1', $scans[4]->invoiceId);
        self::assertSame(20261121, $scans[4]->destinationTag);
        // Xaman sets no Flags field at all.
        self::assertSame(0, $scans[4]->flags);
    }

    public function testTheResultOfTxReadsLikeTheAccountTxEntry(): void
    {
        $fromList = self::merchantList();

        $refused = LedgerTransaction::fromTx(self::recorded('tx-failed-tag-needed.json'));
        self::assertEquals($fromList[5], $refused);

        $pathed = LedgerTransaction::fromTx(self::recorded('tx-incoming-pathed.json'));
        self::assertEquals($fromList[3], $pathed);
    }

    public function testASourceTagIsRead(): void
    {
        $result = self::recorded('tx-incoming-pathed.json');
        $result['SourceTag'] = 4294967295;

        self::assertSame(4294967295, LedgerTransaction::fromTx($result)->sourceTag);
    }

    public function testTagZeroIsATagAndNotTheAbsenceOfOne(): void
    {
        $result = self::recorded('tx-incoming-pathed.json');
        $result['DestinationTag'] = 0;
        $result['SourceTag'] = 0;

        $transaction = LedgerTransaction::fromTx($result);

        self::assertSame(0, $transaction->destinationTag);
        self::assertSame(0, $transaction->sourceTag);
    }

    /**
     * A transaction a node knows but has not validated: not final, so neither succeeded nor
     * failed, and nothing it says about a result or an amount is read.
     */
    public function testATransactionThatIsNotValidatedYetIsNeitherSucceededNorFailed(): void
    {
        $result = self::recorded('tx-incoming-pathed.json');
        $result['validated'] = false;
        unset($result['ledger_index'], $result['inLedger']);

        $pending = LedgerTransaction::fromTx($result);

        self::assertFalse($pending->validated);
        self::assertNull($pending->result);
        self::assertNull($pending->ledgerIndex);
        self::assertNull($pending->delivered);
        self::assertFalse($pending->succeeded());
        self::assertFalse($pending->failed());
    }

    public function testADeliveredAmountOfAnUnknownKindIsNullAndTheTransactionStillReads(): void
    {
        $result = self::recorded('tx-incoming-pathed.json');
        $result['meta']['delivered_amount'] = ['mpt_issuance_id' => '0000012FFD9EE5DA93AC614B4DB94D7E0FCE415CA51BED47', 'value' => '1'];

        $transaction = LedgerTransaction::fromTx($result);

        self::assertTrue($transaction->succeeded());
        self::assertNull($transaction->delivered);

        $result['meta']['delivered_amount'] = 'unavailable';
        self::assertNull(LedgerTransaction::fromTx($result)->delivered);
    }

    /**
     * Xahau answers in the same shape. The list recorded for the sync tests has two `Remit`s:
     * validated, successful, with a destination, and without a delivered amount.
     */
    public function testXahauEntriesReadTheSameAndARemitDeliversNothingReadable(): void
    {
        $entries = json_decode(
            (string) file_get_contents(__DIR__ . '/../Xahau/fixtures/account-tx.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['result']['transactions'];

        $transactions = array_map(LedgerTransaction::fromAccountTx(...), $entries);
        $remits = array_values(array_filter(
            $transactions,
            static fn (LedgerTransaction $transaction): bool => $transaction->type === 'Remit',
        ));

        self::assertCount(9, $transactions);
        self::assertCount(2, $remits);
        foreach ($remits as $remit) {
            self::assertTrue($remit->succeeded());
            self::assertFalse($remit->isPayment());
            self::assertNotNull($remit->destination);
            self::assertNull($remit->delivered);
        }
    }

    #[DataProvider('unusableEntries')]
    public function testAnEntryThatCannotBeReliedOnIsAnErrorNotNothing(callable $break): void
    {
        $entry = self::entries('account-tx-merchant.json')[6];

        $this->expectException(XrplRpcException::class);

        LedgerTransaction::fromAccountTx($break($entry));
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function unusableEntries(): array
    {
        $without = static fn (string ...$path): callable => static function (array $entry) use ($path): array {
            $last = array_pop($path);
            $node = &$entry;
            foreach ($path as $key) {
                $node = &$node[$key];
            }
            unset($node[$last]);

            return $entry;
        };
        $with = static fn (string $field, mixed $value): callable => static function (array $entry) use ($field, $value): array {
            $entry['tx'][$field] = $value;

            return $entry;
        };

        return [
            'no tx object' => [$without('tx')],
            'no hash' => [$without('tx', 'hash')],
            'a hash that is not one' => [$with('hash', 'ABC123')],
            'no validated flag' => [$without('validated')],
            'validated without meta' => [$without('meta')],
            'validated without a result' => [$without('meta', 'TransactionResult')],
            'validated without a ledger index' => [$without('tx', 'ledger_index')],
            'no transaction type' => [$without('tx', 'TransactionType')],
            'no sending account' => [$without('tx', 'Account')],
            'a destination tag as a string' => [$with('DestinationTag', '4711')],
            'a destination tag beyond 32 bits' => [$with('DestinationTag', 4294967296)],
            'a negative source tag' => [$with('SourceTag', -1)],
            'an invoice id that is not 64 hex digits' => [$with('InvoiceID', 'ABC')],
            'a destination that is not a string' => [$with('Destination', ['rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk'])],
            'flags that are not a number' => [$with('Flags', '0')],
        ];
    }

    public function testTheErrorNamesTheTransactionAndWhatIsMissing(): void
    {
        $entry = self::entries('account-tx-merchant.json')[6];
        unset($entry['meta']['TransactionResult']);

        try {
            LedgerTransaction::fromAccountTx($entry);
            self::fail('A validated entry without a result must not read.');
        } catch (XrplRpcException $exception) {
            self::assertStringContainsString('F1021650B10ABE15E323D76DD38D6987923D59B5B6284D24A033B98812FDC36B', $exception->getMessage());
            self::assertStringContainsString('meta.TransactionResult', $exception->getMessage());
        }
    }

    /**
     * @return list<LedgerTransaction>
     */
    private static function merchantList(): array
    {
        return array_map(LedgerTransaction::fromAccountTx(...), self::entries('account-tx-merchant.json'));
    }

    /**
     * @return list<LedgerTransaction>
     */
    private static function walletScans(): array
    {
        return array_map(LedgerTransaction::fromAccountTx(...), self::entries('account-tx-wallet-scans.json'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(string $fixture): array
    {
        return self::recorded($fixture)['transactions'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function recorded(string $fixture): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR)['result'];
    }
}
