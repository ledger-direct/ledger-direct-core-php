<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;

/**
 * The refund was not created. `notices` says why, in the same codes a preview gives; nothing
 * was stored.
 */
final class RefundRefusedException extends RuntimeException implements LedgerDirectException
{
    /**
     * @param list<RefundNotice> $notices the blocking ones
     */
    public function __construct(public readonly array $notices)
    {
        parent::__construct('The refund was refused: ' . implode(', ', array_map(
            static fn (RefundNotice $notice): string => $notice->code,
            $notices,
        )) . '.');
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
