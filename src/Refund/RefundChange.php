<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

/**
 * What a transaction on the ledger does to a refund: it executes it, or it is a finding on it.
 */
final readonly class RefundChange
{
    /**
     * @param Refund $before the refund as it was read
     * @param Refund $after the refund to store in its place
     * @param RefundFinding|null $finding the finding that was added; null when the refund was executed
     */
    public function __construct(
        public Refund $before,
        public Refund $after,
        public ?RefundFinding $finding = null,
    ) {
    }

    public function isExecution(): bool
    {
        return $this->finding === null;
    }
}
