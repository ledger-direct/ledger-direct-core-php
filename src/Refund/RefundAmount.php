<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * From an amount in the shop's currency to the tokens to pay back.
 *
 * The customer paid for the order at the rate of the quote, so a part of the order's value is
 * the same part of the tokens: **refund × requested tokens ÷ order value**, computed exactly and
 * rounded up to the asset's quote precision. 80.00 paid as 86.40 USDC, 20.00 back:
 * 20 × 86.40 ÷ 80 = 21.60.
 *
 * Not "refund ÷ stored rate": the rate in the record is a float, so that division lands a hair
 * beside the exact share, and rounding up turns a hair above it into a whole step more.
 */
final class RefundAmount
{
    /**
     * @param string $fiatAmount what goes back, in the shop's currency
     * @param string $requestedTokens what the quote asked for
     * @param string $orderValue what the quote was made for, in the shop's currency
     * @param int $decimals the asset's quote precision; see decimalsFor()
     * @param bool $roundUp false gives the share rounded down instead: what a caller compares
     *     with when it has to tell a rounding step from a real excess
     * @return string a plain decimal
     */
    public static function fromFiat(
        string $fiatAmount,
        string $requestedTokens,
        string $orderValue,
        int $decimals,
        bool $roundUp = true,
    ): string {
        $fiat = self::positive($fiatAmount, 'refund amount');
        $requested = self::positive($requestedTokens, 'requested token amount');
        $value = self::positive($orderValue, 'order value');

        // Rounded up: the customer never gets less than their share for a rounding's sake (the
        // amount a payment still has to give back caps it afterwards).
        return PaymentIntent::plainDecimal(
            $fiat->multipliedBy($requested)->dividedBy(
                $value,
                $decimals,
                $roundUp ? self::rounding('Up', 'UP') : self::rounding('Down', 'DOWN'),
            )
        );
    }

    /**
     * Whether $orderValue is the value the intent's quote was made for: quoting it again at the
     * stored rate gives exactly the requested amount. The platform knows the order's value, the
     * record does not; an order changed after the quote no longer passes, and the refund then
     * has to be entered in tokens.
     */
    public static function orderValueMatchesQuote(PaymentIntent $intent, string $orderValue): bool
    {
        $decimals = self::decimalsFor($intent);
        if ($decimals === null || !($intent->exchangeRate > 0.0)) {
            return false;
        }

        try {
            $value = BigDecimal::of($orderValue);
            if (!$value->isPositive()) {
                return false;
            }

            // The quote's own arithmetic (PriceService::quoteAmount()).
            $quoted = $value->dividedBy((string) $intent->exchangeRate, $decimals, self::rounding('HalfUp', 'HALF_UP'));
        } catch (MathException) {
            return false;
        }

        return $quoted->isEqualTo(BigDecimal::of($intent->amountRequestedValue()));
    }

    /**
     * The precision a quote in the intent's asset uses — five places for a native asset, two for
     * a stablecoin (ChainCatalog) — or null for a type the catalog does not know.
     */
    public static function decimalsFor(PaymentIntent $intent): ?int
    {
        $descriptors = ChainCatalog::forType($intent->type);

        return $descriptors === null ? null : $descriptors[1]->displayDecimals;
    }

    private static function positive(string $value, string $name): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidArgumentException("The {$name} '{$value}' is not a decimal number.", previous: $exception);
        }

        if (!$decimal->isPositive()) {
            throw new InvalidArgumentException("The {$name} must be above zero, got '{$value}'.");
        }

        return $decimal;
    }

    /**
     * brick/math renamed its RoundingMode cases from SCREAMING_SNAKE to PascalCase; the supported
     * range has both (see PriceService::halfUp()).
     */
    private static function rounding(string $pascal, string $screaming): RoundingMode
    {
        /** @var RoundingMode $mode */
        $mode = constant(RoundingMode::class . '::' . (defined(RoundingMode::class . '::' . $pascal) ? $pascal : $screaming));

        return $mode;
    }
}
