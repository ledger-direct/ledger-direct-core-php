<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Translates between XRPL's on-the-wire amount encoding and the shape
 * {@see \Hardcastle\LedgerDirect\Core\Payment\PaymentIntent} stores.
 *
 * The ledger encodes XRP as an integer string of *drops* (1 XRP = 1,000,000
 * drops) and every issued currency as an `IssuedCurrencyAmount` object —
 * so `meta.delivered_amount` is `"15063780"` for an XRP payment but
 * `{currency, value, issuer}` for an RLUSD one. That asymmetry is ledger
 * encoding, not a platform detail: it belongs here, once, rather than in
 * every adapter.
 */
final class XrplAmount
{
    /** XRP has exactly six decimal places; one drop is the smallest unit. */
    public const XRP_DECIMALS = 6;

    /**
     * Drops (integer string, as the ledger sends them) → XRP.
     *
     * Exact: the division by 10^6 always terminates, so nothing is rounded
     * away here.
     */
    public static function dropsToXrp(string|int $drops): float
    {
        $drops = (string) $drops;

        if (preg_match('/^\d+$/', $drops) !== 1) {
            throw new InvalidArgumentException("Drops must be a non-negative integer string, got '{$drops}'.");
        }

        return BigDecimal::of($drops)->withPointMovedLeft(self::XRP_DECIMALS)->toFloat();
    }

    /**
     * XRP → drops, the inverse. Adapters need this to build a payment
     * request (URI/QR code), where XRP amounts are denominated in drops.
     *
     * Rejects anything finer than one drop instead of silently rounding it:
     * an amount the ledger cannot represent is a bug in the caller, not
     * something to paper over while customer money is involved.
     */
    public static function xrpToDrops(float|string $xrp): string
    {
        try {
            $drops = BigDecimal::of((string) $xrp)->withPointMovedRight(self::XRP_DECIMALS)->toBigInteger();
        } catch (MathException $exception) {
            throw new InvalidArgumentException(
                "'{$xrp}' is not an XRP amount representable in drops.",
                previous: $exception,
            );
        }

        if ($drops->isNegative()) {
            throw new InvalidArgumentException("XRP amount must not be negative, got '{$xrp}'.");
        }

        return (string) $drops;
    }

    /**
     * Any XRPL wire amount → the PaymentIntent amount shape: a drops string
     * becomes an XRP float, an IssuedCurrencyAmount passes through as the
     * `{currency, value, issuer}` array PaymentIntent expects.
     *
     * @param string|int|array<string, mixed> $amount
     * @return float|array{currency: string, value: string, issuer: string}
     */
    public static function decode(string|int|array $amount): float|array
    {
        if (!is_array($amount)) {
            return self::dropsToXrp($amount);
        }

        foreach (['currency', 'value', 'issuer'] as $key) {
            if (!isset($amount[$key])) {
                throw new InvalidArgumentException("Issued currency amount is missing '{$key}'.");
            }
        }

        return [
            'currency' => (string) $amount['currency'],
            'value' => (string) $amount['value'],
            'issuer' => (string) $amount['issuer'],
        ];
    }
}
