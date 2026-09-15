<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The wall clock. PSR-20 ships the interface only, so this is the default
 * every service falls back to when no clock is injected. A platform passes
 * its own — Symfony's Clock, a Carbon-backed one in Laravel, a frozen one
 * in tests — through LedgerDirect::create().
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
