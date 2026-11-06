<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price\Oracle;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Bitrue's public ticker, no API key. Bitrue quotes against USDT only, so
 * this oracle answers USD — with USDT taken as USD — and nothing else; an
 * EUR request gets 0.0 without a call.
 *
 * Kept for XAH, which in November 2026 trades on Bitrue alone: the
 * aggregators report that one market, so this is the source they read
 * from, not an independent second opinion (INVARIANTS.md, "Xahau").
 */
final class BitrueOracle implements OracleInterface
{
    private const URL = 'https://openapi.bitrue.com/api/v1/ticker/price?symbol=';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    /**
     * The last price of `<base>USDT`, as USD. 0.0 — "no price on this feed" —
     * for any quote currency but USD and for a symbol Bitrue does not list.
     */
    public function getCurrentPriceForPair(string $baseCode, string $quoteCode): float
    {
        if (strtoupper($quoteCode) !== 'USD') {
            return 0.0;
        }

        $request = $this->requestFactory->createRequest('GET', self::URL . strtoupper($baseCode) . 'USDT');
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode((string) $response->getBody(), true);

        if (is_array($data) && isset($data['price']) && is_numeric($data['price'])) {
            return (float) $data['price'];
        }

        return 0.0;
    }
}
