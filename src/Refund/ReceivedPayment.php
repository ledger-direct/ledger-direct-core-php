<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;

/**
 * One payment an order received, and what of it can still go back.
 *
 * A refund always belongs to one payment: it goes back in that payment's asset and never
 * exceeds what that payment delivered. `limits` says what is possible right now — per purpose,
 * the most a new refund may ask for; a purpose that is not possible is not in it.
 */
final readonly class ReceivedPayment
{
    /**
     * @param int $paidAt Unix time of the ledger that holds the payment
     * @param bool $inQuotedAsset the payment is in the asset the order asked for
     * @param string $refunded what executed refunds of this payment delivered
     * @param string $pending what open refund requests of this payment ask for
     * @param string $refundable delivered minus refunded minus pending, never below zero
     * @param array<string, string> $limits Refund::PURPOSE_* => the most a new refund may ask for
     * @param list<Refund> $refunds every refund of this payment, by serial
     */
    public function __construct(
        public string $hash,
        public int $ledgerIndex,
        public int $paidAt,
        public string $sender,
        public LedgerAmount $delivered,
        public bool $inQuotedAsset,
        public string $refunded,
        public string $pending,
        public string $refundable,
        public array $limits,
        public array $refunds,
    ) {
    }

    /** The most a new refund with this purpose may ask for, or null when the purpose is not possible. */
    public function limitFor(string $purpose): ?string
    {
        return $this->limits[$purpose] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'ledger_index' => $this->ledgerIndex,
            'paid_at' => $this->paidAt,
            'sender' => $this->sender,
            'delivered' => [
                'value' => $this->delivered->value,
                'currency' => $this->delivered->currency,
                'issuer' => $this->delivered->issuer,
            ],
            'in_quoted_asset' => $this->inQuotedAsset,
            'refunded' => $this->refunded,
            'pending' => $this->pending,
            'refundable' => $this->refundable,
            'limits' => $this->limits,
            'refunds' => array_map(static fn (Refund $refund): array => $refund->toArray(), $this->refunds),
        ];
    }
}
