<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Hardcastle\LedgerDirect\Core\Price\Oracle\BitrueOracle;
use Hardcastle\LedgerDirect\Core\Price\Oracle\CoingeckoOracle;

/**
 * XAH, Xahau's native asset: five decimal places like XRP. Binance and
 * Kraken do not list it. Coingecko and Bitrue — the one market XAH trades
 * on — are read like every oracle set: averaged when they agree, no price
 * when two feeds disagree. Two feeds of one market catch a stale or broken
 * aggregator, not a manipulated market. Bitrue answers USD only
 * (INVARIANTS.md, "Xahau").
 */
final class XahPriceProvider extends AbstractPriceProvider
{
    public const CRYPTO_CODE = 'XAH';

    public const ROUND_PLACES = 5;

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
            new BitrueOracle($this->httpClient, $this->requestFactory),
        ];
    }
}
