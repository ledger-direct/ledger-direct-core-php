<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use RuntimeException;

/**
 * No free destination tag was found within the attempt budget — a safety
 * guard against an unbounded retry loop, not an expected outcome given the
 * ~2.14 billion-value range.
 */
final class DestinationTagsExhaustedException extends RuntimeException
{
}
