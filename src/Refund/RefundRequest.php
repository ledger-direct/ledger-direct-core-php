<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * What a merchant asks for when they click "pay back": which payment, for what purpose, how
 * much, and where to. The core answers with a preview, and creates the refund from the very
 * same request.
 *
 * Everything a person typed comes in as they typed it — the amounts and the tag are strings —
 * so that the checks on it happen in one place, the same for every platform.
 *
 * The amount is given in exactly one of three ways: `amount` in tokens, `fiatAmount` in the
 * shop's currency together with `orderValue`, or `everything`.
 */
final readonly class RefundRequest
{
    /**
     * @param PaymentIntent $intent the order's payment record
     * @param string $platformReference what the platform files the refund under, e.g. the id of
     *     the order's payment transaction
     * @param string $sourceHash the incoming payment to pay back
     * @param string $purpose Refund::PURPOSE_*
     * @param string|null $amount tokens to pay back
     * @param string|null $fiatAmount the amount to pay back in the shop's currency
     * @param string|null $orderValue what the quote was made for, in the shop's currency; the
     *     platform knows it, the record does not
     * @param bool $everything all that the payment still has to give for this purpose
     * @param string|null $destination an address the customer named; null pays back to the sender
     * @param string|null $destinationTag the tag the customer named, as typed; null or '' for none
     * @param string|null $destinationTagRepeat the tag typed a second time: an address carries a
     *     checksum, a tag does not, and a transposed digit credits a stranger
     * @param bool $ownWalletConfirmed the merchant confirms that the customer called the
     *     destination their own wallet — the only way to a refund without a tag
     * @param string|null $reason the merchant's note
     * @param string|null $createdBy who asks, as the platform names its users. Required: every
     *     refund rests on the person who entered its destination or vouched for it, and the
     *     record names them
     */
    public function __construct(
        public PaymentIntent $intent,
        public string $platformReference,
        public string $sourceHash,
        public string $purpose,
        public ?string $amount = null,
        public ?string $fiatAmount = null,
        public ?string $orderValue = null,
        public bool $everything = false,
        public ?string $destination = null,
        public ?string $destinationTag = null,
        public ?string $destinationTagRepeat = null,
        public bool $ownWalletConfirmed = false,
        public ?string $reason = null,
        public ?string $createdBy = null,
    ) {
    }
}
