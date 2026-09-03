<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Payment;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SettlementPolicyTest extends TestCase
{
    private const USDC = [
        'currency' => '5553444300000000000000000000000000000000',
        'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
    ];

    public function testNothingPaidIsNeverSettled(): void
    {
        $policy = new SettlementPolicy();

        self::assertFalse($policy->isSettled($this->xrpQuote(100.0)));
        self::assertSame('100', $policy->shortfall($this->xrpQuote(100.0)));
    }

    public function testNativeAssetSettlesWithinTheDefaultTolerance(): void
    {
        $policy = new SettlementPolicy();

        foreach ([100.0, 100.5, 99.85] as $paid) {
            self::assertTrue($policy->isSettled($this->xrpQuote(100.0)->withFulfillment('H', $paid)), "paid {$paid}");
            self::assertNull($policy->shortfall($this->xrpQuote(100.0)->withFulfillment('H', $paid)));
        }

        foreach ([99.8499, 50.0, 0.0] as $paid) {
            self::assertFalse($policy->isSettled($this->xrpQuote(100.0)->withFulfillment('H', $paid)), "paid {$paid}");
        }
    }

    public function testNativeAssetShortfallIsTheFullDifferenceNotTheToleratedOne(): void
    {
        $policy = new SettlementPolicy();

        self::assertSame('26.75411', $policy->shortfall($this->xrpQuote(26.75411)));
        self::assertSame('25.91411', $policy->shortfall($this->xrpQuote(26.75411)->withFulfillment('H', 0.84)));
    }

    public function testAPlatformMayConfigureTheNativeAssetTolerance(): void
    {
        $strict = new SettlementPolicy('0');
        $loose = new SettlementPolicy('0.01');

        self::assertFalse($strict->isSettled($this->xrpQuote(100.0)->withFulfillment('H', 99.999)));
        self::assertTrue($strict->isSettled($this->xrpQuote(100.0)->withFulfillment('H', 100.0)));
        self::assertTrue($loose->isSettled($this->xrpQuote(100.0)->withFulfillment('H', 99.0)));
        self::assertFalse($loose->isSettled($this->xrpQuote(100.0)->withFulfillment('H', 98.99)));
    }

    public function testToleranceMustBeAFractionBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettlementPolicy('1');
    }

    public function testToleranceMustNotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettlementPolicy('-0.1');
    }

    public function testIssuedCurrencyMustDeliverAtLeastTheQuotedValueExactly(): void
    {
        $policy = new SettlementPolicy();

        self::assertTrue($policy->isSettled($this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '39'])));
        self::assertTrue($policy->isSettled($this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '40.5'])));
        self::assertFalse($policy->isSettled($this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '38.999'])));
        self::assertSame('0.001', $policy->shortfall($this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '38.999'])));
    }

    /**
     * A token with the right name from another issuer, or another token from the right issuer,
     * is not what was asked for: it neither settles nor reduces what is still due.
     */
    public function testIssuedCurrencyFromAnotherIssuerOrWithAnotherCodeDoesNotCount(): void
    {
        $policy = new SettlementPolicy();
        $otherIssuer = ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39.00'];
        $otherCurrency = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => self::USDC['issuer'], 'value' => '39.00'];

        foreach ([$otherIssuer, $otherCurrency] as $paid) {
            $intent = $this->usdcQuote('39.00')->withFulfillment('H', $paid);

            self::assertFalse($policy->isSettled($intent));
            self::assertSame('39', $policy->shortfall($intent), 'the whole amount is still due, as a plain decimal');
        }
    }

    public function testValueHelpersFlattenBothAmountShapes(): void
    {
        $xrp = $this->xrpQuote(26.75411)->withFulfillment('H', 0.84);
        self::assertSame('26.75411', $xrp->amountRequestedValue());
        self::assertSame('0.84', $xrp->amountPaidValue());

        $usdc = $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '1.16']);
        self::assertSame('39.00', $usdc->amountRequestedValue());
        self::assertSame('1.16', $usdc->amountPaidValue());

        self::assertNull($this->xrpQuote(1.0)->amountPaidValue());
        self::assertSame('100', $this->xrpQuote(100.0)->amountRequestedValue(), 'no trailing ".0"');
        self::assertSame('0.000001', $this->xrpQuote(0.000001)->amountRequestedValue(), 'no exponent notation');
    }

    private function xrpQuote(float $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.25,
            amountRequested: $requested,
            destinationAccount: 'rMerchant',
            destinationTag: 1,
        );
    }

    private function usdcQuote(string $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: self::USDC + ['value' => $requested],
            destinationAccount: 'rMerchant',
            destinationTag: 1,
        );
    }
}
