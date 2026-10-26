<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class XrplTransactionTest extends TestCase
{
    public function testACtidIsCPlusFifteenUppercaseHexDigits(): void
    {
        self::assertTrue(XrplTransaction::isValidCtid('C000006400000001'));
        self::assertTrue(XrplTransaction::isValidCtid('C0035A5900030000'));
        self::assertTrue(XrplTransaction::isValidCtid('C18FA505003A5359'), 'a Xahau mainnet CTID has the same form');

        foreach (['C0000000000000000000000', 'c000006400000001', 'C00000640000000', 'C00000640000000G', '', '0035A5900030000'] as $bad) {
            self::assertFalse(XrplTransaction::isValidCtid($bad), var_export($bad, true));
        }
    }

    public function testReadsAnXrpDeliveredAmountAsAFloat(): void
    {
        $transaction = $this->transaction(['delivered_amount' => '15063780']);

        self::assertSame(15.06378, $transaction->getDeliveredAmount());
    }

    public function testReadsAnIssuedCurrencyDeliveredAmountAsAnArray(): void
    {
        $amount = (new StablecoinRegistry())->getRLUSDAmount('mainnet', '12.34');
        $transaction = $this->transaction(['delivered_amount' => $amount]);

        self::assertSame($amount, $transaction->getDeliveredAmount());
    }

    /**
     * The raw metadata field a partial payment carries, for metadata that
     * didn't come straight from a server adding the lowercase alias.
     */
    public function testFallsBackToTheRawDeliveredAmountField(): void
    {
        $transaction = $this->transaction(['DeliveredAmount' => '15063780']);

        self::assertSame(15.06378, $transaction->getDeliveredAmount());
    }

    public function testReturnsNullWhenTheTransactionDeliveredNothing(): void
    {
        self::assertNull($this->transaction([])->getDeliveredAmount());
    }

    public function testRefusesAnUnavailableDeliveredAmount(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->transaction(['delivered_amount' => 'unavailable'])->getDeliveredAmount();
    }

    /**
     * The whole point of putting this in the core: what comes off the
     * ledger goes straight into the record, with no adapter-side decoding.
     */
    public function testTheResultFeedsWithFulfillmentDirectly(): void
    {
        $transaction = $this->transaction(['delivered_amount' => '15063780']);

        $intent = PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: 15.06378,
            destinationAccount: 'rDestination',
            destinationTag: 42,
        )->withFulfillment($transaction->hash, $transaction->getDeliveredAmount(), $transaction->ctid);

        self::assertSame(15.06378, $intent->amountPaid);
        self::assertSame('HASH', $intent->hash);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function transaction(array $meta): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: '100',
            network: 'testnet',
            hash: 'HASH',
            ctid: 'C000006400000001',
            account: 'rSender',
            destination: 'rDestination',
            destinationTag: 42,
            date: 800000000,
            meta: $meta,
            tx: [],
        );
    }
}
