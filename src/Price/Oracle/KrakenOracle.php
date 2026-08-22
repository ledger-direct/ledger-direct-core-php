<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price\Oracle;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

final class KrakenOracle implements OracleInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function getCurrentPriceForPair(string $baseCode, string $quoteCode): float
    {
        $pair = $baseCode . $quoteCode;
        $url = 'https://api.kraken.com/0/public/Ticker?pair=' . $pair;

        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode((string) $response->getBody(), true);

        // Kraken names pairs inconsistently (e.g. 'XXRPZUSD', 'XXRPZEUR', 'USDCUSD');
        // a single-pair query returns exactly one entry under 'result', read generically.
        $result = $data['result'] ?? [];
        $ticker = is_array($result) ? reset($result) : false;

        if (is_array($ticker) && isset($ticker['c'][0])) {
            return (float) $ticker['c'][0];
        }

        return 0.0;
    }
}
