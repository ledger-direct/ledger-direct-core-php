<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Payment;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private const USDC = [
        'currency' => '5553444300000000000000000000000000000000',
        'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
    ];

    private SettlementPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SettlementPolicy();
    }

    public function testNothingPaidAndAValidQuoteIsWaitingWithTheSecondsLeft(): void
    {
        $status = $this->derive($this->xrpQuote(100.0, expiry: self::NOW + 480));

        self::assertSame(PaymentStatus::WAITING, $status->state());
        self::assertFalse($status->isTerminal());
        self::assertSame([
            'schema_version' => 1,
            'state' => 'waiting',
            'base_asset' => 'XRP',
            'amount_requested' => 100.0,
            'amount_paid' => null,
            'shortfall' => null,
            'seconds_left' => 480,
        ], $status->toArray());
    }

    public function testNothingPaidAndAnExpiredQuoteIsExpired(): void
    {
        $status = $this->derive($this->xrpQuote(100.0, expiry: self::NOW - 1));

        self::assertSame(PaymentStatus::EXPIRED, $status->state());
        self::assertFalse($status->isTerminal());
        self::assertSame([
            'schema_version' => 1,
            'state' => 'expired',
            'base_asset' => 'XRP',
            'amount_requested' => 100.0,
            'amount_paid' => null,
            'shortfall' => null,
            'seconds_left' => null,
        ], $status->toArray());
    }

    /**
     * The boundary is part of the contract: a quote whose expiry equals the current second is
     * already expired, so `seconds_left` is never 0 in `waiting`.
     */
    public function testAQuoteExpiresAtItsExpiryTimestampNotAfterIt(): void
    {
        self::assertSame(PaymentStatus::WAITING, $this->derive($this->xrpQuote(100.0, expiry: self::NOW + 1))->state());
        self::assertSame(1, $this->derive($this->xrpQuote(100.0, expiry: self::NOW + 1))->toArray()['seconds_left']);
        self::assertSame(PaymentStatus::EXPIRED, $this->derive($this->xrpQuote(100.0, expiry: self::NOW))->state());
    }

    /**
     * PaymentIntent allows a quote without an expiry. It never expires, and there is no countdown
     * to show — `seconds_left` is null, not some invented large number.
     */
    public function testAQuoteWithoutAnExpiryWaitsForeverWithNoCountdown(): void
    {
        $status = $this->derive($this->xrpQuote(100.0, expiry: null));

        self::assertSame(PaymentStatus::WAITING, $status->state());
        self::assertNull($status->toArray()['seconds_left']);
    }

    public function testAnUnderpaymentInTheQuotedAssetIsPartial(): void
    {
        $intent = $this->xrpQuote(26.75411, expiry: self::NOW + 480)->withFulfillment('H', 0.84);
        $status = $this->derive($intent);

        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertFalse($status->isTerminal());
        self::assertSame([
            'schema_version' => 1,
            'state' => 'partial',
            'base_asset' => 'XRP',
            'amount_requested' => 26.75411,
            'amount_paid' => 0.84,
            'shortfall' => 25.91411,
            'seconds_left' => null,
        ], $status->toArray());
    }

    /**
     * The shortfall takes the shape of the request, so an adapter formats it with the same code
     * path: an IssuedCurrencyAmount carrying the *quoted* currency and issuer.
     */
    public function testAnIssuedCurrencyShortfallCarriesTheQuotedCurrencyAndIssuer(): void
    {
        $intent = $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '30']);
        $status = $this->derive($intent);

        self::assertSame(PaymentStatus::PARTIAL, $status->state());
        self::assertSame(self::USDC + ['value' => '30'], $status->toArray()['amount_paid']);
        self::assertSame([
            'currency' => self::USDC['currency'],
            'value' => '9',
            'issuer' => self::USDC['issuer'],
        ], $status->toArray()['shortfall']);
    }

    /**
     * The customer paid, their wallet says so, and it was a different token. `amount_paid` shows
     * what actually arrived — the adapter can name the wrong token — while `shortfall` is the
     * whole request in the *quoted* asset: nothing was credited.
     */
    public function testAPaymentInAnotherAssetIsWrongAssetWithTheFullAmountStillDue(): void
    {
        $rlusdFromRightIssuer = [
            'currency' => '524C555344000000000000000000000000000000',
            'issuer' => self::USDC['issuer'],
            'value' => '39',
        ];
        $status = $this->derive($this->usdcQuote('39.00')->withFulfillment('H', $rlusdFromRightIssuer));

        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
        self::assertFalse($status->isTerminal());
        self::assertSame($rlusdFromRightIssuer, $status->toArray()['amount_paid']);
        self::assertSame([
            'currency' => self::USDC['currency'],
            'value' => '39',
            'issuer' => self::USDC['issuer'],
        ], $status->toArray()['shortfall']);
    }

    public function testAWrongIssuerIsWrongAssetToo(): void
    {
        $usdcFromSomebodyElse = ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39'];

        $status = $this->derive($this->usdcQuote('39.00')->withFulfillment('H', $usdcFromSomebodyElse));

        self::assertSame(PaymentStatus::WRONG_ASSET, $status->state());
    }

    public function testAFullPaymentIsSettledAndTerminal(): void
    {
        $status = $this->derive($this->xrpQuote(100.0, expiry: self::NOW + 480)->withFulfillment('H', 100.0));

        self::assertSame(PaymentStatus::SETTLED, $status->state());
        self::assertTrue($status->isTerminal());
        self::assertSame([
            'schema_version' => 1,
            'state' => 'settled',
            'base_asset' => 'XRP',
            'amount_requested' => 100.0,
            'amount_paid' => 100.0,
            'shortfall' => null,
            'seconds_left' => null,
        ], $status->toArray());
    }

    public function testSettlementFollowsThePolicyNotAnExactComparison(): void
    {
        $withinTolerance = $this->xrpQuote(100.0)->withFulfillment('H', 99.85);
        $overpaid = $this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '40.5']);

        self::assertSame(PaymentStatus::SETTLED, $this->derive($withinTolerance)->state());
        self::assertSame(PaymentStatus::SETTLED, $this->derive($overpaid)->state());
        self::assertSame(PaymentStatus::PARTIAL, $this->derive($this->xrpQuote(100.0)->withFulfillment('H', 99.8499), new SettlementPolicy())->state());
        self::assertSame(PaymentStatus::PARTIAL, $this->derive($this->xrpQuote(100.0)->withFulfillment('H', 99.85), new SettlementPolicy('0'))->state());
    }

    /**
     * The derivation order is part of the contract. Real money on an expired quote is `partial`
     * or `wrong_asset`, never `expired`; a settling payment is `settled` whatever the clock says.
     */
    public function testAPaymentOnAnExpiredQuoteIsReportedBeforeTheExpiry(): void
    {
        $expired = self::NOW - 3600;

        self::assertSame(
            PaymentStatus::PARTIAL,
            $this->derive($this->xrpQuote(100.0, expiry: $expired)->withFulfillment('H', 10.0))->state(),
        );
        self::assertSame(
            PaymentStatus::WRONG_ASSET,
            $this->derive($this->usdcQuote('39.00', expiry: $expired)->withFulfillment('H', ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39']))->state(),
        );
        self::assertSame(
            PaymentStatus::SETTLED,
            $this->derive($this->xrpQuote(100.0, expiry: $expired)->withFulfillment('H', 100.0))->state(),
        );
    }

    public function testOnlySettledIsTerminal(): void
    {
        $statuses = [
            'waiting' => $this->derive($this->xrpQuote(100.0, expiry: self::NOW + 480)),
            'expired' => $this->derive($this->xrpQuote(100.0, expiry: self::NOW - 1)),
            'partial' => $this->derive($this->xrpQuote(100.0)->withFulfillment('H', 10.0)),
            'wrong_asset' => $this->derive($this->usdcQuote('39.00')->withFulfillment('H', ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39'])),
            'settled' => $this->derive($this->xrpQuote(100.0)->withFulfillment('H', 100.0)),
        ];

        foreach ($statuses as $expected => $status) {
            self::assertSame($expected, $status->state());
            self::assertSame($expected === 'settled', $status->isTerminal(), $expected);
        }
    }

    public function testTheClockDefaultsToNow(): void
    {
        $status = PaymentStatus::fromIntent($this->xrpQuote(100.0, expiry: time() + 3600), $this->policy);

        self::assertSame(PaymentStatus::WAITING, $status->state());
        self::assertGreaterThan(3500, $status->toArray()['seconds_left']);
        self::assertLessThanOrEqual(3600, $status->toArray()['seconds_left']);
    }

    public function testThePayloadIsTheSameForBothAmountShapes(): void
    {
        $xrp = $this->derive($this->xrpQuote(100.0)->withFulfillment('H', 10.0))->toArray();
        $usdc = $this->derive($this->usdcQuote('39.00')->withFulfillment('H', self::USDC + ['value' => '30']))->toArray();

        self::assertSame(array_keys($xrp), array_keys($usdc));
        self::assertSame('schema_version', array_key_first($xrp));
        self::assertSame(PaymentStatus::SCHEMA_VERSION, $xrp['schema_version']);
    }

    private function derive(PaymentIntent $intent, ?SettlementPolicy $policy = null): PaymentStatus
    {
        return PaymentStatus::fromIntent($intent, $policy ?? $this->policy, self::NOW);
    }

    private function xrpQuote(float $requested, ?int $expiry = self::NOW + 900): PaymentIntent
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
            expiry: $expiry,
        );
    }

    private function usdcQuote(string $requested, ?int $expiry = self::NOW + 900): PaymentIntent
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
            expiry: $expiry,
        );
    }
}
