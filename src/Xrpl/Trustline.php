<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * A receiving account's trust line to an issuer, from the account's side as `account_lines`
 * reports it. `headroom()` is what the account can still receive before its limit — a payment
 * beyond it does not arrive.
 */
final readonly class Trustline
{
    /**
     * @param bool $frozen either side froze the line (`freeze`, `freeze_peer`)
     * @param bool $authorized the issuer authorized the line (`peer_authorized`); matters only
     *     when the issuer requires authorization
     */
    public function __construct(
        public string $currency,
        public string $issuer,
        public string $balance,
        public string $limit,
        public bool $frozen,
        public bool $authorized,
    ) {
    }

    /**
     * @param array<string, mixed> $line one entry of `account_lines`
     */
    public static function fromAccountLine(array $line): self
    {
        return new self(
            currency: (string) ($line['currency'] ?? ''),
            issuer: (string) ($line['account'] ?? ''),
            balance: (string) ($line['balance'] ?? '0'),
            limit: (string) ($line['limit'] ?? '0'),
            frozen: (bool) ($line['freeze'] ?? false) || (bool) ($line['freeze_peer'] ?? false),
            authorized: (bool) ($line['peer_authorized'] ?? false),
        );
    }

    /** Limit minus balance, as a plain decimal string; never negative. */
    public function headroom(): string
    {
        $room = BigDecimal::of($this->limit)->minus(BigDecimal::of($this->balance));

        return PaymentIntent::plainDecimal($room->isNegative() ? BigDecimal::zero() : $room);
    }
}
