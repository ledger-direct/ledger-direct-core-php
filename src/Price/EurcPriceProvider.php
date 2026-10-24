<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Hardcastle\LedgerDirect\Core\Price\Oracle\CoingeckoOracle;

/**
 * EURC, Circle's euro stablecoin — Coingecko only, two places, and an EUR
 * peg fast path: the first non-USD peg in the core, symmetric to the USD
 * one for USDC and RLUSD (INVARIANTS.md, "Stellar", Conversion).
 */
final class EurcPriceProvider extends AbstractPriceProvider
{
    public const CRYPTO_CODE = 'EURC';

    public const ROUND_PLACES = 2;

    public function getCurrentExchangeRate(string $quoteCurrency, bool $round = false): float|false
    {
        // EUR-peg fast path: EURC is (intended to be) pegged to EUR, no oracle call needed.
        if ($quoteCurrency === 'EUR') {
            return 1.0;
        }

        return parent::getCurrentExchangeRate($quoteCurrency, $round);
    }

    protected function cryptoCode(): string
    {
        return self::CRYPTO_CODE;
    }

    protected function roundPlaces(): int
    {
        return self::ROUND_PLACES;
    }

    protected function oracles(): array
    {
        return [new CoingeckoOracle($this->httpClient, $this->requestFactory)];
    }
}
