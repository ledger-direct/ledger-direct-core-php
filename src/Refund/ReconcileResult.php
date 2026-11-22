<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

/**
 * What one look at a paying account's transactions changed: the refunds it found executed and
 * the findings it added, so a platform can book the one and tell the merchant about the other.
 *
 * `complete` false means the ledger could not be read to the end — the node did not answer, an
 * answer was not usable, or the look ran out of its page budget. That is **not** "nothing
 * found": whatever came before the gap is in here and stored, and what comes after is unknown.
 */
final readonly class ReconcileResult
{
    /**
     * @param string|null $error why the look is incomplete
     * @param list<Refund> $executed refunds this look found executed, as stored
     * @param list<RefundChange> $findings the findings this look added
     * @param list<Refund> $unreachable watched refunds created in a ledger far beyond the node's
     *     newest one: another epoch of a test network that was reset. No payment for them can be
     *     found on this ledger; they are not looked for.
     */
    public function __construct(
        public string $account,
        public string $network,
        public bool $complete,
        public ?string $error = null,
        public array $executed = [],
        public array $findings = [],
        public array $unreachable = [],
    ) {
    }

    public function changedAnything(): bool
    {
        return $this->executed !== [] || $this->findings !== [];
    }
}
