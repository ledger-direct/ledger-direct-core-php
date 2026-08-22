<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Hardcastle\LedgerDirect\Core\Price\Oracle\CoingeckoOracle;

final class UsdcPriceProvider extends AbstractPriceProvider
{
    public const CRYPTO_CODE = 'USDC';

    public const ROUND_PLACES = 2;

    public function getCurrentExchangeRate(string $quoteCurrency, bool $round = false): float|false
    {
        // USD-peg fast path: USDC is (intended to be) pegged to USD, no oracle call needed.
        if ($quoteCurrency === 'USD') {
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
        return [
            new CoingeckoOracle($this->httpClient, $this->requestFactory),
        ];
    }
}
