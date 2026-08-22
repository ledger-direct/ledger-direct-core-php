<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use RuntimeException;

/**
 * No oracle in the fixed set produced a usable, non-divergent price —
 * distinct from a programming error so callers can catch it specifically
 * (e.g. to show "try again shortly" rather than a hard failure).
 */
final class PriceUnavailableException extends RuntimeException
{
}
