<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price\Oracle;

/**
 * A single price feed for one currency pair. Returns 0.0 when the pair has
 * no price on this feed (unknown symbol, empty/malformed response) — that's
 * how a PriceProvider knows to skip this oracle, not an exception.
 */
interface OracleInterface
{
    public function getCurrentPriceForPair(string $baseCode, string $quoteCode): float;
}
