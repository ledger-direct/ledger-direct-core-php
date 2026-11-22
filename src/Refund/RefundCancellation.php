<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

/**
 * What became of a request to cancel a refund.
 *
 * Before a refund is cancelled the ledger is read once more: a refund that was paid a moment ago
 * must come back as executed, not as cancelled. `reconciled` is what that look found — for this
 * refund and for every other refund of the same paying account — and a platform handles it like
 * the result of any other pass.
 */
final readonly class RefundCancellation
{
    /**
     * @param Refund|null $refund the refund as it is now; null when there is none with that id
     * @param ReconcileResult|null $reconciled null when the ledger was not read because the
     *     refund was not awaiting execution anyway
     */
    public function __construct(
        public ?Refund $refund,
        public ?ReconcileResult $reconciled = null,
    ) {
    }

    public function isCancelled(): bool
    {
        return $this->refund?->isCancelled() === true;
    }

    /**
     * The refund still awaits execution because the ledger could not be read to the end: nobody
     * knows whether it was paid, so it was not withdrawn.
     */
    public function couldNotVerify(): bool
    {
        return $this->refund?->isAwaitingExecution() === true && $this->reconciled?->complete === false;
    }
}
