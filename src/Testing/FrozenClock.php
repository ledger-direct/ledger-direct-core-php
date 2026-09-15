<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock that stands still until a test moves it. Lets quote
 * expiry, rate-cache freshness and the sync throttle be tested without
 * sleeping and without touching PHP's own time().
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(int|DateTimeImmutable $now = 1_700_000_000)
    {
        $this->now = is_int($now) ? (new DateTimeImmutable('@' . $now)) : $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
