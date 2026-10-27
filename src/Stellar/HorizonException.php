<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;
use Throwable;

/**
 * A Horizon request failed: transport error, non-2xx status, malformed
 * body. Distinct from an empty page, which is a normal answer.
 */
final class HorizonException extends RuntimeException implements LedgerDirectException
{
    /**
     * @param int|null $status the HTTP status when there was a response
     * @param int|null $retryAfter seconds Horizon asked us to wait, on a 429 — the caller must
     *     honour it rather than retry immediately (SDF's public instance allows 3600 requests
     *     per hour and IP)
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
