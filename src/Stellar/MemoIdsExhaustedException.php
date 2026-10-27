<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;

/**
 * The receiving account's memo-id counter has run past the range. Reachable
 * only after ~4.29 billion identifiers on one account.
 */
final class MemoIdsExhaustedException extends RuntimeException implements LedgerDirectException
{
}
