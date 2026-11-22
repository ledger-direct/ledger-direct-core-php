<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * An amount exactly as the ledger states it: the value as a plain decimal string in the asset's
 * own units, and for an issued currency its code and issuer.
 *
 * No float anywhere. {@see XrplAmount::decode()} turns drops into an XRP float because that is
 * the shape a PaymentIntent records; here two amounts are compared to decide whether a payment
 * is exactly the one that was asked for, and a float can only say "close".
 *
 * The native asset carries no code: it is XRP on the XRP Ledger and XAH on Xahau, and whoever
 * knows the chain names it.
 *
 * A currency code is compared as a node states it. A node writes a standard code as three
 * characters and any other as 40 hex digits, so two amounts that both come from a node (or from
 * the registry, which is checked against one) agree on the spelling. The 40-digit spelling of a
 * standard code is not folded into its three characters here.
 */
final readonly class LedgerAmount
{
    /** The native asset has six decimal places; the ledger states it in millionths ("drops"). */
    private const NATIVE_DECIMALS = 6;

    private function __construct(
        public string $value,
        public ?string $currency,
        public ?string $issuer,
    ) {
    }

    /**
     * @param string $value in the asset's units ("1.07"), not in drops
     */
    public static function native(string $value): self
    {
        $plain = PaymentIntent::plainDecimal(self::decimal($value));
        $point = strpos($plain, '.');
        if ($point !== false && strlen($plain) - $point - 1 > self::NATIVE_DECIMALS) {
            throw new InvalidArgumentException("'{$value}' is finer than the native asset's six decimal places.");
        }

        return new self($plain, null, null);
    }

    public static function issued(string $currency, string $issuer, string $value): self
    {
        if ($issuer === '') {
            throw new InvalidArgumentException('An issued currency amount needs an issuer.');
        }

        return new self(PaymentIntent::plainDecimal(self::decimal($value)), self::currencyCode($currency), $issuer);
    }

    /**
     * An amount as a node sends it: a string of drops for the native asset, or an
     * IssuedCurrencyAmount object. Anything else is refused, including the ledger's
     * `unavailable` marker and amount kinds this class does not model (a Multi-Purpose Token
     * amount has an `mpt_issuance_id` instead of currency and issuer).
     */
    public static function fromWire(mixed $amount): self
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (is_string($amount)) {
            if (preg_match('/^\d+$/', $amount) !== 1) {
                throw new InvalidArgumentException("'{$amount}' is not an amount in drops.");
            }

            // The drops are the unscaled value at scale six: exact, nothing is rounded.
            return new self(
                PaymentIntent::plainDecimal(BigDecimal::ofUnscaledValue($amount, self::NATIVE_DECIMALS)),
                null,
                null,
            );
        }

        if (!is_array($amount)
            || !is_string($amount['currency'] ?? null)
            || !is_string($amount['issuer'] ?? null)
            || !is_string($amount['value'] ?? null)
        ) {
            throw new InvalidArgumentException('Not an amount in drops and not an issued currency amount.');
        }

        return self::issued($amount['currency'], $amount['issuer'], $amount['value']);
    }

    public function isNative(): bool
    {
        return $this->currency === null;
    }

    /**
     * The same asset: both native, or the same currency code from the same issuer. The same
     * code from another issuer is another asset.
     */
    public function sameAsset(self $other): bool
    {
        return $this->currency === $other->currency && $this->issuer === $other->issuer;
    }

    /** The same asset and exactly the same value. */
    public function equals(self $other): bool
    {
        return $this->sameAsset($other)
            && BigDecimal::of($this->value)->isEqualTo(BigDecimal::of($other->value));
    }

    private static function decimal(string $value): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidArgumentException("'{$value}' is not a decimal amount.", previous: $exception);
        }

        if ($decimal->isNegative()) {
            throw new InvalidArgumentException("An amount must not be negative, got '{$value}'.");
        }

        return $decimal;
    }

    /**
     * A currency code is three characters, case-sensitive (`USD` and `usd` are two currencies),
     * or 40 hex digits, the same in either letter case and kept here in upper case.
     */
    private static function currencyCode(string $currency): string
    {
        if (preg_match('/^[0-9A-Fa-f]{40}$/', $currency) === 1) {
            return strtoupper($currency);
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("'{$currency}' is not a currency code.");
        }

        return $currency;
    }
}
