<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Refund;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Refund\RefundAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundAmountTest extends TestCase
{
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    #[DataProvider('shares')]
    public function testAShareOfTheOrderValueIsTheSameShareOfTheTokens(
        string $fiat,
        string $requested,
        string $orderValue,
        int $decimals,
        string $tokens,
    ): void {
        self::assertSame($tokens, RefundAmount::fromFiat($fiat, $requested, $orderValue, $decimals));
    }

    /**
     * @return array<string, array{string, string, string, int, string}>
     */
    public static function shares(): array
    {
        return [
            // 80.00 paid as 86.40 USDC, 20.00 back: a quarter of the tokens.
            'a quarter' => ['20.00', '86.40', '80.00', 2, '21.6'],
            'everything is exactly what was asked' => ['80.00', '86.40', '80.00', 2, '86.4'],
            // 10 x 86.40 / 81 = 10.6666...: never less than the share.
            'rounded up to the cent' => ['10', '86.40', '81', 2, '10.67'],
            // 49.99 x 15.06378 / 100 = 7.530383622
            'a native asset to five places' => ['49.99', '15.06378', '100.00', 5, '7.53039'],
            'a result that needs no rounding stays as it is' => ['50', '15.06378', '100', 5, '7.53189'],
            'the smallest unit of the currency' => ['0.01', '86.40', '80.00', 2, '0.02'],
        ];
    }

    public function testTheShareRoundedDownIsWhatTellsARoundingStepFromARealExcess(): void
    {
        // 66.67 x 45.45455 / 100 = 30.304548485
        self::assertSame('30.30455', RefundAmount::fromFiat('66.67', '45.45455', '100.00', 5));
        self::assertSame('30.30454', RefundAmount::fromFiat('66.67', '45.45455', '100.00', 5, roundUp: false));
        self::assertSame('21.6', RefundAmount::fromFiat('20.00', '86.40', '80.00', 2, roundUp: false), 'an exact share is the same either way');
    }

    #[DataProvider('notAmounts')]
    public function testWhatIsNotAPositiveAmountIsRefused(string $fiat, string $requested, string $orderValue): void
    {
        $this->expectException(InvalidArgumentException::class);

        RefundAmount::fromFiat($fiat, $requested, $orderValue, 2);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function notAmounts(): array
    {
        return [
            'nothing to refund' => ['0', '86.40', '80.00'],
            'a negative refund' => ['-5', '86.40', '80.00'],
            'a refund that is not a number' => ['twenty', '86.40', '80.00'],
            'an order worth nothing' => ['20', '86.40', '0'],
            'no tokens requested' => ['20', '0', '80.00'],
        ];
    }

    /**
     * The platform says what the order is worth; the record can only check it: quoted again at
     * the stored rate, it must give the amount that was asked.
     */
    public function testTheOrderValueOfATokenQuoteIsRecognised(): void
    {
        // 80.00 at 0.92592592592593 per token, rounded half-up to the cent: 86.40.
        $intent = self::intent('rlusd-payment', 'RLUSD', 0.92592592592593, ['currency' => self::RLUSD, 'value' => '86.40', 'issuer' => self::ISSUER]);

        self::assertTrue(RefundAmount::orderValueMatchesQuote($intent, '80.00'));
        self::assertTrue(RefundAmount::orderValueMatchesQuote($intent, '80'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, '75.00'), 'an order changed after the quote');
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, '0'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, '-80'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, 'eighty'));
    }

    public function testTheOrderValueOfANativeQuoteIsRecognised(): void
    {
        // 100.00 at 2.2 per XRP, rounded half-up to five places: 45.45455.
        $intent = self::intent('xrp-payment', 'XRP', 2.2, 45.45455);

        self::assertTrue(RefundAmount::orderValueMatchesQuote($intent, '100.00'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, '100.01'));
    }

    public function testAStablecoinAtItsPegIsItsOwnOrderValue(): void
    {
        $intent = self::intent('rlusd-payment', 'RLUSD', 1.0, ['currency' => self::RLUSD, 'value' => '19.99', 'issuer' => self::ISSUER], 'USD');

        self::assertTrue(RefundAmount::orderValueMatchesQuote($intent, '19.99'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote($intent, '20.00'));
    }

    public function testWithoutAUsableRateOrAKnownAssetNothingMatches(): void
    {
        self::assertFalse(RefundAmount::orderValueMatchesQuote(self::intent('xrp-payment', 'XRP', 0.0, 45.45455), '100.00'));
        self::assertFalse(RefundAmount::orderValueMatchesQuote(
            self::intent('loyalty-payment', 'PTS', 1.0, ['currency' => 'PTS', 'value' => '100', 'issuer' => self::ISSUER]),
            '100',
        ));
    }

    public function testTheQuotePrecisionComesFromTheCatalog(): void
    {
        self::assertSame(5, RefundAmount::decimalsFor(self::intent('xrp-payment', 'XRP', 2.2, 45.45455)));
        self::assertSame(2, RefundAmount::decimalsFor(self::intent('rlusd-payment', 'RLUSD', 1.0, ['currency' => self::RLUSD, 'value' => '1', 'issuer' => self::ISSUER])));
        self::assertSame(5, RefundAmount::decimalsFor(self::intent('xahau-xah-payment', 'XAH', 0.05, 2000.0, chain: 'XAHAU')));
        self::assertNull(RefundAmount::decimalsFor(self::intent('loyalty-payment', 'PTS', 1.0, ['currency' => 'PTS', 'value' => '100', 'issuer' => self::ISSUER])));
    }

    /**
     * @param float|array{currency: string, value: string, issuer: string} $amountRequested
     */
    private static function intent(
        string $type,
        string $asset,
        float $rate,
        float|array $amountRequested,
        string $quoteCurrency = 'EUR',
        string $chain = 'XRPL',
    ): PaymentIntent {
        return PaymentIntent::quote(
            type: $type,
            chain: $chain,
            network: 'testnet',
            baseAsset: $asset,
            quoteCurrency: $quoteCurrency,
            pairing: $asset . '/' . $quoteCurrency,
            exchangeRate: $rate,
            amountRequested: $amountRequested,
            destinationAccount: 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL',
            destinationTag: 31337,
        );
    }
}
