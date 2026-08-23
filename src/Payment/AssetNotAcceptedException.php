<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use RuntimeException;

/**
 * The merchant currently doesn't accept payments in the requested base
 * asset (ConfigProviderInterface::isAssetEnabled() returned false) — an
 * expected, specifically-catchable business outcome, not a programming
 * error.
 */
final class AssetNotAcceptedException extends RuntimeException
{
}
