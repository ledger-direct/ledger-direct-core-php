<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

/**
 * Rate and amount, and nothing else: what PriceService::quoteAmount()
 * returns before any chain puts its envelope around the number. XRPL's
 * PriceQuote wraps it in `{currency, value, issuer}` from the XRPL registry;
 * Stellar's intent service does the same with its own registry.
 */
final readonly class AmountQuote
{
    /**
     * @param string $amount the requested amount as a decimal string at the asset's scale, e.g. "2.20000"
     */
    public function __construct(
        public string $baseAsset,
        public string $quoteCurrency,
        public string $pairing,
        public float $exchangeRate,
        public string $amount,
    ) {
    }
}
