<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

/**
 * One thing the core has to say about a refund before it exists: why it cannot be created, or
 * what the merchant should know before signing it. The code is stable — an adapter maps it to a
 * text; `context` carries what the text may show (an account, a tag, an amount).
 *
 * `blocks` says whether the refund is refused because of it. Two kinds of reasons block: a rule
 * that keeps money from being lost (a destination without a tag where one may be needed), and a
 * payment the ledger would refuse anyway (no trust line), which would only cost the merchant a
 * fee. A notice that does not block is advice.
 */
final readonly class RefundNotice
{
    /* --- the request itself --- */

    /** The transaction is not a payment the order received, or not on this network. */
    public const SOURCE_NOT_FOUND = 'source_not_found';

    /** The purpose does not fit the payment: e.g. an order refund of a payment in another asset. */
    public const PURPOSE_NOT_ALLOWED = 'purpose_not_allowed';

    /** Not an amount, not above zero, or finer than the asset allows. */
    public const AMOUNT_INVALID = 'amount_invalid';

    /** More than the payment still has to give back. Context: `refundable`. */
    public const AMOUNT_EXCEEDS_REFUNDABLE = 'amount_exceeds_refundable';

    /** More than was paid in excess. Context: `surplus`. */
    public const AMOUNT_EXCEEDS_SURPLUS = 'amount_exceeds_surplus';

    /** The order value given does not belong to the quote; the token amount has to be entered directly. */
    public const FIAT_BASIS_MISMATCH = 'fiat_basis_mismatch';

    /** An open refund to the same destination, tag, asset and amount exists. Context: `refund`. */
    public const DUPLICATE_OPEN_REFUND = 'duplicate_open_refund';

    /** The node could not be asked; nothing is created on a guess. Context: `error`. */
    public const LEDGER_UNAVAILABLE = 'ledger_unavailable';

    /* --- the destination and its tag --- */

    /** Not an address of this chain. */
    public const DESTINATION_INVALID = 'destination_invalid';

    /** The destination is the account that would pay. */
    public const DESTINATION_IS_PAYING_ACCOUNT = 'destination_is_paying_account';

    /** The destination is the token's issuer. */
    public const DESTINATION_IS_ISSUER = 'destination_is_issuer';

    /** The destination account requires a destination tag and none was given. */
    public const TAG_REQUIRED = 'tag_required';

    /** The tag and its repetition differ, or it is not an unsigned 32-bit number. */
    public const TAG_INVALID = 'tag_invalid';

    /**
     * No tag, and the sender may be an account many customers share (an exchange). Context:
     * `sender`, `reason` (`requires_tag`, `source_tag` or `source_unreadable`), and with a
     * source tag `source_tag` — the tag to ask the customer about.
     */
    public const SENDER_MAY_BE_SHARED = 'sender_may_be_shared';

    /** No tag, and nobody has confirmed that the address is the customer's own wallet. */
    public const CONFIRMATION_REQUIRED = 'confirmation_required';

    /* --- what the ledger would do with the payment --- */

    /** The destination does not exist on the ledger. Context: `reserve` for the native asset. */
    public const DESTINATION_NOT_ACTIVATED = 'destination_not_activated';

    /** Only senders the destination has pre-authorized can pay it (`DepositAuth`). */
    public const DESTINATION_DEPOSIT_AUTH = 'destination_deposit_auth';

    /** The destination has no trust line for the token. Context: `currency`, `issuer`. */
    public const TRUSTLINE_MISSING = 'trustline_missing';

    /** The issuer requires authorized trust lines and has not authorized this one. */
    public const TRUSTLINE_NOT_AUTHORIZED = 'trustline_not_authorized';

    /** The trust line is frozen. */
    public const TRUSTLINE_FROZEN = 'trustline_frozen';

    /** The trust line has no room for the amount. Context: `headroom`. */
    public const TRUSTLINE_FULL = 'trustline_full';

    /** The issuer froze every trust line in its tokens. */
    public const ISSUER_FROZEN = 'issuer_frozen';

    /** The issuer charges a transfer fee; a payment request for the exact amount can fail. Context: `rate`. */
    public const TRANSFER_FEE = 'transfer_fee';

    /**
     * The customer paid through the ledger's exchange: they spent one asset, the shop received
     * another, and the refund is in the received one. Context: `spent`, `received`.
     */
    public const PAID_ACROSS_ASSETS = 'paid_across_assets';

    /** The destination does not enforce the tag: check it in the wallet before signing. */
    public const CHECK_TAG_IN_WALLET = 'check_tag_in_wallet';

    /**
     * @param array<string, string> $context
     */
    public function __construct(
        public string $code,
        public bool $blocks,
        public array $context = [],
    ) {
    }

    /**
     * @return array{code: string, blocks: bool, context: array<string, string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'blocks' => $this->blocks, 'context' => $this->context];
    }
}
