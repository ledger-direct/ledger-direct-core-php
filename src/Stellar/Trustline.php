<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * A receiving account's trust line for one issued asset, as Horizon
 * reports it. `headroom()` is what the account can still receive before
 * the limit — a payment beyond it fails with op_line_full.
 */
final readonly class Trustline
{
    public function __construct(
        public string $assetCode,
        public string $issuer,
        public string $balance,
        public string $limit,
        public bool $authorized,
    ) {
    }

    /** Limit minus balance, as a plain decimal string; never negative. */
    public function headroom(): string
    {
        $room = BigDecimal::of($this->limit)->minus(BigDecimal::of($this->balance));

        return PaymentIntent::plainDecimal($room->isNegative() ? BigDecimal::zero() : $room);
    }
}
