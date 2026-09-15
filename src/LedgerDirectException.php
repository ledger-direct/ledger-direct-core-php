<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

use Throwable;

/**
 * Marker for every runtime failure the core raises: no oracle reachable,
 * the XRPL node answering with an error, the destination-tag range
 * exhausted, an asset the merchant has switched off. One `catch` in a
 * platform's exception handler covers them all.
 *
 * Deliberately not on the InvalidArgumentExceptions the value objects
 * throw — those are programming errors in the caller, not conditions a
 * running shop recovers from.
 */
interface LedgerDirectException extends Throwable
{
}
