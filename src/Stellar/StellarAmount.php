<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * Translates a Stellar payment's amount into the shape PaymentIntent
 * stores: a float for XLM, `{currency, value, issuer}` for an issued asset
 * — the asset code in plain text, the value as a plain decimal.
 *
 * Stellar amounts arrive as decimal strings with seven places
 * ("1.5000000"); nothing here rounds, it only drops the trailing zeros the
 * record does not carry.
 */
final class StellarAmount
{
    /** XLM has exactly seven decimal places; one stroop is the smallest unit. */
    public const XLM_DECIMALS = 7;

    /**
     * @return float|array{currency: string, value: string, issuer: string}
     */
    public static function decode(StellarPayment $payment): float|array
    {
        if ($payment->assetIssuer === null) {
            return BigDecimal::of($payment->amount)->toFloat();
        }

        return [
            'currency' => $payment->assetCode,
            'value' => PaymentIntent::plainDecimal(BigDecimal::of($payment->amount)),
            'issuer' => $payment->assetIssuer,
        ];
    }

    /**
     * An amount as Stellar wants it on the wire: at most seven places,
     * exact. Rejects anything finer than a stroop rather than rounding it.
     */
    public static function toWire(float|string $amount): string
    {
        $decimal = BigDecimal::of((string) $amount);

        if ($decimal->getScale() > self::XLM_DECIMALS) {
            $stripped = BigDecimal::of(PaymentIntent::plainDecimal($decimal));
            if ($stripped->getScale() > self::XLM_DECIMALS) {
                throw new \InvalidArgumentException("'{$amount}' is finer than a stroop.");
            }
            $decimal = $stripped;
        }

        return PaymentIntent::plainDecimal($decimal);
    }
}
