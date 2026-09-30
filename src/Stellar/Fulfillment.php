<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * What pays for a Stellar intent: the payment operations that count towards
 * it, newest first, and what they delivered in total. The sibling of
 * Xrpl\Fulfillment — separate because that one is typed to XrplTransaction
 * and records a CTID, which Stellar has no counterpart for.
 */
final readonly class Fulfillment
{
    /**
     * @param list<StellarPayment> $payments contributing operations, newest first; never empty
     * @param float|array{currency: string, value: string, issuer: string} $amountPaid what they delivered in total
     */
    public function __construct(
        public array $payments,
        public float|array $amountPaid,
    ) {
        if ($payments === []) {
            throw new InvalidArgumentException('A Fulfillment needs at least one contributing payment.');
        }
    }

    /** Hash of the newest contributing transaction — what the intent records. */
    public function hash(): string
    {
        return $this->payments[0]->hash;
    }

    public function count(): int
    {
        return count($this->payments);
    }

    /** The intent with this fulfillment's hash and summed amount; ctid stays null on Stellar. */
    public function applyTo(PaymentIntent $intent): PaymentIntent
    {
        return $intent->withFulfillment($this->hash(), $this->amountPaid, null);
    }
}
