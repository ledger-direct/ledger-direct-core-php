<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price\Oracle;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

final class CoingeckoOracle implements OracleInterface
{
    private const CURRENCY_CODE_MAP = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'USDT' => 'tether',
        'XRP' => 'ripple',
        'USDC' => 'usd-coin',
        'RLUSD' => 'ripple-usd',
    ];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function getCurrentPriceForPair(string $baseCode, string $quoteCode): float
    {
        $baseId = self::mapCurrencyCode($baseCode);
        $quoteId = self::mapCurrencyCode($quoteCode);

        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . $baseId . '&vs_currencies=' . $quoteId;

        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data[$baseId][$quoteId])) {
            return (float) $data[$baseId][$quoteId];
        }

        return 0.0;
    }

    private static function mapCurrencyCode(string $currencyCode): string
    {
        return self::CURRENCY_CODE_MAP[$currencyCode] ?? strtolower($currencyCode);
    }
}
