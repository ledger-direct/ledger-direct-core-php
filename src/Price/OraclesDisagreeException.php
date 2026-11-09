<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

/**
 * The oracles answered and their prices were all rejected by the divergence
 * filter. Unlike oracles that could not be reached, this says something is
 * wrong with the prices, so no earlier rate stands in for it.
 */
final class OraclesDisagreeException extends PriceUnavailableException
{
}
