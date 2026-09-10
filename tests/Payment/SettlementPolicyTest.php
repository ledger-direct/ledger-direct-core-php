<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Payment;

use Brick\Math\BigDecimal;
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

    /**
     * The case an adapter has to explain to a customer: they paid, their wallet says so, and the
     * order is no closer to being settled. Naming it here is what keeps four platforms from
     * inventing four wordings for the same situation.
     */
    public function testAWrongAssetIsNamedAsSuchAndCreditsNothing(): void
    {
        $policy = new SettlementPolicy();
        $otherIssuer = ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39.00'];
        $otherCurrency = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => self::USDC['issuer'], 'value' => '39.00'];

        foreach ([$otherIssuer, $otherCurrency] as $paid) {
            $intent = $this->usdcQuote('39.00')->withFulfillment('H', $paid);

            self::assertTrue($policy->isWrongAsset($intent));
            self::assertSame('0', $policy->creditedValue($intent), 'a wrong token is not progress');
            self::assertSame('39', $policy->shortfall($intent));
        }
    }

    public function testTheQuotedTokenCountsTowardsTheOrder(): void
    {
        $policy = new SettlementPolicy();

        $partial = $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '30']);
        self::assertFalse($policy->isWrongAsset($partial));
        self::assertSame('30', $policy->creditedValue($partial));
        self::assertSame('9', $policy->shortfall($partial));

        // Overpayment settles; what counts is what arrived, surplus included.
        $over = $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '40.5']);
        self::assertSame('40.5', $policy->creditedValue($over));
        self::assertNull($policy->shortfall($over));
    }

    /**
     * There is no issuer or currency code on a native amount, so there is nothing to mismatch —
     * an XRP payment can be too small, but never the wrong asset.
     */
    public function testANativeAmountIsNeverTheWrongAsset(): void
    {
        $policy = new SettlementPolicy();
        $underpaid = $this->xrpQuote(26.75411)->withFulfillment('H', 0.84);

        self::assertFalse($policy->isWrongAsset($underpaid));
        self::assertSame('0.84', $policy->creditedValue($underpaid));
    }

    public function testNothingPaidCreditsZeroWithoutBlamingTheAsset(): void
    {
        $policy = new SettlementPolicy();

        self::assertFalse($policy->isWrongAsset($this->usdcQuote('39.00')));
        self::assertSame('0', $policy->creditedValue($this->usdcQuote('39.00')));
        self::assertSame('0', $policy->creditedValue($this->xrpQuote(1.0)));
    }

    /**
     * The two numbers a payment page prints side by side have to agree: whatever is credited plus
     * whatever is outstanding is exactly what was asked for. Nothing may fall between them.
     */
    public function testCreditedAndOutstandingAlwaysAddUpToTheRequest(): void
    {
        $policy = new SettlementPolicy();
        $wrongIssuer = ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '12'];

        $unsettled = [
            $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '30']),
            $this->usdcQuote('39.00')->withFulfillment('H', $wrongIssuer),
            $this->usdcQuote('39.00'),
            $this->xrpQuote(26.75411)->withFulfillment('H', 0.84),
            $this->xrpQuote(26.75411),
        ];

        foreach ($unsettled as $intent) {
            self::assertFalse($policy->isSettled($intent));
            // Compared as decimals, not as strings: "39" and "39.00" are the same number,
            // and the scale a value happens to carry is not part of the promise.
            self::assertSame(
                PaymentIntent::plainDecimal(BigDecimal::of($intent->amountRequestedValue())),
                PaymentIntent::plainDecimal(
                    BigDecimal::of($policy->creditedValue($intent))
                        ->plus(BigDecimal::of((string) $policy->shortfall($intent)))
                ),
            );
        }
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
