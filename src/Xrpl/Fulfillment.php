<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * What pays for an intent: the transactions that count towards it, and what
 * they delivered in total. Produced by {@see SyncService::findFulfillmentFor()}.
 *
 * More than one transaction can contribute — two partial payments add up,
 * a top-up of the shortfall settles — so this carries the whole list. The
 * intent itself (schema v1) still records a single hash and ctid: those of
 * the newest contributing transaction. The full list is always recoverable
 * from the transaction table via SyncService::findTransactions().
 *
 * applyTo() is the one place an adapter gets hash, ctid and the summed
 * amount into a PaymentIntent, so nobody assembles them from different
 * objects.
 */
final readonly class Fulfillment
{
    /**
     * @param XrplTransaction[] $transactions contributing transactions, newest first; never empty
     * @param float|array{currency: string, value: string, issuer: string} $amountPaid what they delivered in total, decoded
     */
    public function __construct(
        public array $transactions,
        public float|array $amountPaid,
    ) {
        if ($transactions === []) {
            throw new InvalidArgumentException('A Fulfillment needs at least one contributing transaction.');
        }
    }

    /** Hash of the newest contributing transaction — what the intent records. */
    public function hash(): string
    {
        return $this->transactions[0]->hash;
    }

    /** CTID of the newest contributing transaction — what the intent records. */
    public function ctid(): string
    {
        return $this->transactions[0]->ctid;
    }

    /** How many transactions contributed. */
    public function count(): int
    {
        return count($this->transactions);
    }

    /**
     * The intent with this fulfillment's hash, ctid and summed amount set.
     * Immutable, like withFulfillment() itself.
     */
    public function applyTo(PaymentIntent $intent): PaymentIntent
    {
        return $intent->withFulfillment($this->hash(), $this->amountPaid, $this->ctid());
    }
}
