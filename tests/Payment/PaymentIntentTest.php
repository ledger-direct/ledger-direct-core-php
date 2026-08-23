<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Payment;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentIntentTest extends TestCase
{
    public function testFromArrayToArrayRoundTripForXrp(): void
    {
        $data = [
            'schema_version' => 1,
            'type' => 'xrp-payment',
            'chain' => 'XRPL',
            'network' => 'testnet',
            'base_asset' => 'XRP',
            'quote_currency' => 'USD',
            'pairing' => 'XRP/USD',
            'exchange_rate' => 0.55,
            'amount_requested' => 181.81818,
            'destination_account' => 'rDestinationAccount',
            'destination_tag' => 123456,
            'expiry' => 1_700_000_900,
            'hash' => null,
            'ctid' => null,
            'amount_paid' => null,
        ];

        $intent = PaymentIntent::fromArray($data);

        self::assertSame($data, $intent->toArray());
    }

    public function testFromArrayToArrayRoundTripForStablecoin(): void
    {
        $data = [
            'schema_version' => 1,
            'type' => 'rlusd-payment',
            'chain' => 'XRPL',
            'network' => 'mainnet',
            'base_asset' => 'RLUSD',
            'quote_currency' => 'USD',
            'pairing' => 'RLUSD/USD',
            'exchange_rate' => 1.0,
            'amount_requested' => ['currency' => '524C555344000000000000000000000000000000', 'value' => '100.00', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
            'destination_account' => 'rDestinationAccount',
            'destination_tag' => 654321,
            'expiry' => null,
            'hash' => 'ABC123',
            'ctid' => 'C0035A5900030000',
            'amount_paid' => ['currency' => '524C555344000000000000000000000000000000', 'value' => '100.00', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
        ];

        $intent = PaymentIntent::fromArray($data);

        self::assertSame($data, $intent->toArray());
    }

    public function testFromArrayAcceptsDeliveredAmountAsAmountPaidAlias(): void
    {
        $data = $this->xrpQuoteData();
        unset($data['amount_paid']);
        $data['delivered_amount'] = 181.81818;

        $intent = PaymentIntent::fromArray($data);

        self::assertSame(181.81818, $intent->amountPaid);
    }

    public function testFromArrayRejectsMissingSchemaVersion(): void
    {
        $data = $this->xrpQuoteData();
        unset($data['schema_version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing schema_version');

        PaymentIntent::fromArray($data);
    }

    public function testFromArrayRejectsUnsupportedSchemaVersion(): void
    {
        $data = $this->xrpQuoteData();
        $data['schema_version'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported schema_version');

        PaymentIntent::fromArray($data);
    }

    public function testConstructorRejectsArrayAmountForXrp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'USD',
            pairing: 'XRP/USD',
            exchangeRate: 0.55,
            amountRequested: ['currency' => 'XRP', 'value' => '10', 'issuer' => ''],
            destinationAccount: 'rDestinationAccount',
            destinationTag: 123456,
        );
    }

    public function testConstructorRejectsFloatAmountForStablecoin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: 100.0,
            destinationAccount: 'rDestinationAccount',
            destinationTag: 123456,
        );
    }

    public function testConstructorRejectsFloatAmountOnAChainWithNoRegisteredNativeAsset(): void
    {
        // 'BTC' chain isn't in NATIVE_ASSET_BY_CHAIN, so everything on it is
        // treated as an issued currency by default (the safer shape) — a
        // bare float is rejected, not because 'BTC' the asset is unknown,
        // but because it doesn't match a registered native asset for this chain.
        $this->expectException(InvalidArgumentException::class);

        PaymentIntent::quote(
            type: 'btc-payment',
            chain: 'BTC',
            network: 'mainnet',
            baseAsset: 'BTC',
            quoteCurrency: 'USD',
            pairing: 'BTC/USD',
            exchangeRate: 1.0,
            amountRequested: 1.0,
            destinationAccount: 'someAddress',
            destinationTag: 1,
        );
    }

    public function testAcceptsAPreviouslyUnrecognizedAssetOnXrplAsAnIssuedCurrency(): void
    {
        // Proves the open-by-default rule: a merchant-issued token (e.g. a
        // future Loyalty point) the core has never heard of is still
        // accepted, as an issued currency, without any core change —
        // previously this would have thrown "Unknown base_asset".
        $intent = PaymentIntent::quote(
            type: 'loyalty-payment',
            chain: 'XRPL',
            network: 'mainnet',
            baseAsset: 'MYSHOP_POINTS',
            quoteCurrency: 'USD',
            pairing: 'MYSHOP_POINTS/USD',
            exchangeRate: 100.0,
            amountRequested: ['currency' => '4D5953484F505F504F494E545300000000000000', 'value' => '500', 'issuer' => 'rMerchantIssuerAddress'],
            destinationAccount: 'rDestinationAccount',
            destinationTag: 1,
        );

        self::assertSame('MYSHOP_POINTS', $intent->baseAsset);
        self::assertIsArray($intent->amountRequested);
    }

    public function testWithFulfillmentReturnsNewImmutableInstance(): void
    {
        $quote = PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'USD',
            pairing: 'XRP/USD',
            exchangeRate: 0.55,
            amountRequested: 181.81818,
            destinationAccount: 'rDestinationAccount',
            destinationTag: 123456,
        );

        $fulfilled = $quote->withFulfillment('ABC123', 181.81818, 'C0035A5900030000');

        self::assertNull($quote->hash);
        self::assertNull($quote->ctid);
        self::assertNull($quote->amountPaid);
        self::assertSame('ABC123', $fulfilled->hash);
        self::assertSame('C0035A5900030000', $fulfilled->ctid);
        self::assertSame(181.81818, $fulfilled->amountPaid);
        self::assertNotSame($quote, $fulfilled);
    }

    public function testWithFulfillmentWorksWithoutCtidForNonXrplChains(): void
    {
        $quote = PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'USD',
            pairing: 'XRP/USD',
            exchangeRate: 0.55,
            amountRequested: 181.81818,
            destinationAccount: 'rDestinationAccount',
            destinationTag: 123456,
        );

        // Simulates a future chain that has no CTID-equivalent concept.
        $fulfilled = $quote->withFulfillment('0xabc123', 181.81818);

        self::assertSame('0xabc123', $fulfilled->hash);
        self::assertNull($fulfilled->ctid);
        self::assertSame(181.81818, $fulfilled->amountPaid);
    }

    /**
     * @return array<string, mixed>
     */
    private function xrpQuoteData(): array
    {
        return [
            'schema_version' => 1,
            'type' => 'xrp-payment',
            'chain' => 'XRPL',
            'network' => 'testnet',
            'base_asset' => 'XRP',
            'quote_currency' => 'USD',
            'pairing' => 'XRP/USD',
            'exchange_rate' => 0.55,
            'amount_requested' => 181.81818,
            'destination_account' => 'rDestinationAccount',
            'destination_tag' => 123456,
            'expiry' => 1_700_000_900,
            'hash' => null,
            'ctid' => null,
            'amount_paid' => null,
        ];
    }
}
