<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

interface PriceProviderInterface
{
    /**
     * Relative divergence a single oracle's price may have from the mean of
     * all working oracles before it's excluded from the result.
     */
    public const DEFAULT_ALLOWED_DIVERGENCE = 0.05;

    /**
     * @return float|false the current exchange rate, or false if no oracle
     *     in the fixed set produced a usable, non-divergent price.
     */
    public function getCurrentExchangeRate(string $quoteCurrency, bool $round = false): float|false;
}
