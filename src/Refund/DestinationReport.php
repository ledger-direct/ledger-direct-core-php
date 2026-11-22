<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

/**
 * What the ledger says, right now, about an account a refund would go to
 * ({@see \Hardcastle\LedgerDirect\Core\Xrpl\RefundDestinationCheck}).
 *
 * `requiresTag` is the one fact here that a rule hangs on. Everything in `notices` is about
 * whether the ledger would accept the payment — help for the merchant, not protection: a payment
 * the ledger refuses costs a fee and loses nothing.
 */
final readonly class DestinationReport
{
    /**
     * @param bool $exists the account is on the ledger
     * @param bool $requiresTag the account's `RequireDest` flag is set: the ledger refuses a
     *     payment without a destination tag
     * @param list<RefundNotice> $notices
     */
    public function __construct(
        public string $account,
        public bool $exists,
        public bool $requiresTag,
        public array $notices,
    ) {
    }

    /** Whether the ledger would refuse the payment as things stand. */
    public function wouldFail(): bool
    {
        foreach ($this->notices as $notice) {
            if ($notice->blocks) {
                return true;
            }
        }

        return false;
    }

    public function has(string $code): bool
    {
        foreach ($this->notices as $notice) {
            if ($notice->code === $code) {
                return true;
            }
        }

        return false;
    }
}
