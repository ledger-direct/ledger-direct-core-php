<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price\Oracle;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

final class BinanceOracle implements OracleInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function getCurrentPriceForPair(string $baseCode, string $quoteCode): float
    {
        // Binance has no direct XRPUSD pair; USD is quoted against its USDT market instead.
        $symbol = $baseCode . ($quoteCode === 'USD' ? 'USDT' : $quoteCode);
        $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . $symbol;

        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['price'])) {
            return (float) $data['price'];
        }

        return 0.0;
    }
}
