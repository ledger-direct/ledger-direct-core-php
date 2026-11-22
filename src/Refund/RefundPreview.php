<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;

/**
 * What would happen if the request were made now: whether the refund would be created, what
 * stands in its way, what the merchant should know, and what exactly would be requested.
 * Nothing is stored by a preview. Creating runs the same checks again.
 */
final readonly class RefundPreview
{
    /**
     * @param list<RefundNotice> $notices blocking ones and advice, in the order they were found
     * @param LedgerAmount|null $amount the tokens the refund would ask for; null when the
     *     request names no usable amount
     * @param string|null $limit the most the request's purpose allows for this payment right now
     * @param string|null $destination the account the refund would go to
     * @param int|null $destinationTag the tag it would carry; null for none
     * @param string|null $destinationOrigin RefundDestination::ORIGIN_*
     * @param string|null $payingAccount the account the request would ask to pay from
     * @param bool $needsOwnWalletConfirmation without a tag the refund exists only once the
     *     merchant confirms that the address is the customer's own wallet: ask that question
     * @param int|null $sourceTag the `SourceTag` the payment carried. By the ledger's convention
     *     a refund names it as its destination tag — a suggestion to ask the customer about,
     *     never a tag the core uses on its own
     */
    public function __construct(
        public array $notices,
        public ?LedgerAmount $amount,
        public ?string $limit,
        public ?string $destination,
        public ?int $destinationTag,
        public ?string $destinationOrigin,
        public ?string $payingAccount,
        public bool $needsOwnWalletConfirmation,
        public ?int $sourceTag,
    ) {
    }

    /** Whether creating the refund from this request would succeed as things stand. */
    public function isAllowed(): bool
    {
        return $this->blockers() === [];
    }

    /**
     * @return list<RefundNotice>
     */
    public function blockers(): array
    {
        return array_values(array_filter($this->notices, static fn (RefundNotice $notice): bool => $notice->blocks));
    }

    public function has(string $code): bool
    {
        return $this->notice($code) !== null;
    }

    public function notice(string $code): ?RefundNotice
    {
        foreach ($this->notices as $notice) {
            if ($notice->code === $code) {
                return $notice;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->isAllowed(),
            'notices' => array_map(static fn (RefundNotice $notice): array => $notice->toArray(), $this->notices),
            'amount' => $this->amount === null ? null : [
                'value' => $this->amount->value,
                'currency' => $this->amount->currency,
                'issuer' => $this->amount->issuer,
            ],
            'limit' => $this->limit,
            'destination' => $this->destination,
            'destination_tag' => $this->destinationTag === null ? null : (string) $this->destinationTag,
            'destination_origin' => $this->destinationOrigin,
            'paying_account' => $this->payingAccount,
            'needs_own_wallet_confirmation' => $this->needsOwnWalletConfirmation,
            'source_tag' => $this->sourceTag === null ? null : (string) $this->sourceTag,
        ];
    }
}
