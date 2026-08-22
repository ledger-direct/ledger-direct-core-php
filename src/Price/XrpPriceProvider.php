<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Hardcastle\LedgerDirect\Core\Price\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Core\Price\Oracle\CoingeckoOracle;
use Hardcastle\LedgerDirect\Core\Price\Oracle\KrakenOracle;

final class XrpPriceProvider extends AbstractPriceProvider
{
    public const CRYPTO_CODE = 'XRP';

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
            new BinanceOracle($this->httpClient, $this->requestFactory),
            new CoingeckoOracle($this->httpClient, $this->requestFactory),
            new KrakenOracle($this->httpClient, $this->requestFactory),
        ];
    }
}
