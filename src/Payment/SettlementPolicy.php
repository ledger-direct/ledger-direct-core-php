<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Decides whether what arrived on the ledger pays for what was quoted — see INVARIANTS.md,
 * "Settlement". Every platform adapter calls this instead of comparing amounts itself, so an
 * order is called paid under exactly the same conditions everywhere.
 *
 * What "paid" then does on the platform (invoice, order status, emails) is the adapter's concern.
 */
final class SettlementPolicy
{
    /**
     * A native-asset quote is a float rounded to five places and wallets may shave rounding on
     * their side, so a payment within this fraction of the request still counts as complete.
     */
    public const DEFAULT_NATIVE_ASSET_TOLERANCE = '0.0015';

    private readonly BigDecimal $nativeAssetTolerance;

    /**
     * @param string $nativeAssetTolerance fraction of the requested amount a native-asset payment
     *     may fall short by, as a decimal string in [0, 1); platforms pass their configured value
     */
    public function __construct(string $nativeAssetTolerance = self::DEFAULT_NATIVE_ASSET_TOLERANCE)
    {
        $tolerance = BigDecimal::of($nativeAssetTolerance);

        if ($tolerance->isNegative() || $tolerance->isGreaterThanOrEqualTo(1)) {
            throw new InvalidArgumentException(
                "Native asset tolerance must be in [0, 1), got '{$nativeAssetTolerance}'."
            );
        }

        $this->nativeAssetTolerance = $tolerance;
    }

    /**
     * Whether the intent's delivered amount settles its requested amount.
     */
    public function isSettled(PaymentIntent $intent): bool
    {
        if ($intent->amountPaid === null) {
            return false;
        }

        if (is_array($intent->amountRequested)) {
            return is_array($intent->amountPaid)
                && !$this->isWrongAsset($intent)
                && BigDecimal::of((string) $intent->amountPaid['value'])
                    ->isGreaterThanOrEqualTo(BigDecimal::of((string) $intent->amountRequested['value']));
        }

        if (is_array($intent->amountPaid)) {
            return false;
        }

        $requested = BigDecimal::of((string) $intent->amountRequested);
        $acceptable = $requested->minus($requested->multipliedBy($this->nativeAssetTolerance));

        return BigDecimal::of((string) $intent->amountPaid)->isGreaterThanOrEqualTo($acceptable);
    }

    /**
     * Whether what arrived is a different asset than the one quoted: a token with the right name
     * from another issuer, or another token from the right issuer. The customer did pay and their
     * wallet reports success — it simply credits nothing towards this order.
     *
     * Public because every adapter needs it to tell the customer *why* nothing was credited, and
     * because each of them deriving it again is how one rule ends up with four slightly different
     * definitions. Before this existed, two adapters compared issuer and currency themselves while
     * two others inferred it from `shortfall() === amountRequestedValue()`.
     *
     * A native asset can never be the wrong asset: PaymentIntent accepts nothing but a float
     * there, so there is no issuer or currency code to mismatch.
     */
    public function isWrongAsset(PaymentIntent $intent): bool
    {
        if (!is_array($intent->amountRequested) || !is_array($intent->amountPaid)) {
            return false;
        }

        return $intent->amountPaid['currency'] !== $intent->amountRequested['currency']
            || $intent->amountPaid['issuer'] !== $intent->amountRequested['issuer'];
    }

    /**
     * How much of the request has actually been credited, as a plain decimal string — the number
     * a payment page means by "received so far".
     *
     * Deliberately not the delivered amount: a payment in the wrong asset credits **"0"**, however
     * large it was. Showing the delivered value there would present a payment that can never
     * settle the order as progress towards settling it.
     */
    public function creditedValue(PaymentIntent $intent): string
    {
        return PaymentIntent::plainDecimal($this->countablePaidValue($intent));
    }

    /**
     * What is still missing, as a plain decimal string in the requested asset — the number a
     * payment page shows next to "received so far". Null once the intent is settled; the full
     * requested amount while nothing has arrived. For an issued currency from the wrong issuer
     * or with the wrong currency code the whole amount is still due: that payment does not count.
     */
    public function shortfall(PaymentIntent $intent): ?string
    {
        if ($this->isSettled($intent)) {
            return null;
        }

        $requested = BigDecimal::of($intent->amountRequestedValue());
        $paid = $this->countablePaidValue($intent);

        return PaymentIntent::plainDecimal($requested->minus($paid));
    }

    /**
     * The delivered value that counts against the request: an issued currency only if it is the
     * quoted one, nothing at all while nothing has arrived.
     */
    private function countablePaidValue(PaymentIntent $intent): BigDecimal
    {
        if ($intent->amountPaid === null) {
            return BigDecimal::zero();
        }

        if (is_array($intent->amountRequested) !== is_array($intent->amountPaid)) {
            return BigDecimal::zero();
        }

        if ($this->isWrongAsset($intent)) {
            return BigDecimal::zero();
        }

        return BigDecimal::of((string) $intent->amountPaidValue());
    }
}
