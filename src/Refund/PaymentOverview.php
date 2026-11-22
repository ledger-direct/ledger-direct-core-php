<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;

/**
 * Every payment an order received, one by one, and the sums the refund rules need: what was
 * asked, what arrived in the asked asset, what counts for the order and what is excess.
 *
 * All amounts are plain decimal strings in the quoted asset's units, computed exactly.
 */
final readonly class PaymentOverview
{
    /** No order refund has been executed. */
    public const ORDER_REFUND_NONE = 'none';

    /** Executed order refunds returned part of what counts for the order. */
    public const ORDER_REFUND_PARTIAL = 'partial';

    /** Executed order refunds returned everything that counts for the order. */
    public const ORDER_REFUND_FULL = 'full';

    /**
     * @param LedgerAmount $requested what the quote asked for
     * @param string $received the sum of the payments in the quoted asset
     * @param string $credited what of it counts for the order: `received`, at most `requested`
     * @param string $surplus what arrived beyond `requested`
     * @param string $surplusReturnable `surplus` minus what surplus returns already claim
     * @param string $orderRefunded what executed order refunds delivered
     * @param string $orderRefundState ORDER_REFUND_*; decided in tokens against `credited`, not
     *     against the order's value in the shop's currency, which can change after the payment
     * @param list<ReceivedPayment> $payments newest first
     */
    public function __construct(
        public LedgerAmount $requested,
        public string $received,
        public string $credited,
        public string $surplus,
        public string $surplusReturnable,
        public string $orderRefunded,
        public string $orderRefundState,
        public array $payments,
    ) {
    }

    public function find(string $hash): ?ReceivedPayment
    {
        foreach ($this->payments as $payment) {
            if ($payment->hash === strtoupper($hash)) {
                return $payment;
            }
        }

        return null;
    }

    /** Whether any refund of the order's money is open or executed. */
    public function hasOrderRefund(): bool
    {
        foreach ($this->payments as $payment) {
            foreach ($payment->refunds as $refund) {
                if ($refund->purpose === Refund::PURPOSE_ORDER_REFUND && !$refund->isCancelled()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested' => [
                'value' => $this->requested->value,
                'currency' => $this->requested->currency,
                'issuer' => $this->requested->issuer,
            ],
            'received' => $this->received,
            'credited' => $this->credited,
            'surplus' => $this->surplus,
            'surplus_returnable' => $this->surplusReturnable,
            'order_refunded' => $this->orderRefunded,
            'order_refund_state' => $this->orderRefundState,
            'payments' => array_map(static fn (ReceivedPayment $payment): array => $payment->toArray(), $this->payments),
        ];
    }
}
