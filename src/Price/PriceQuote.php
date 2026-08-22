<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

/**
 * The quote fragment of a payment: what a PriceService fills in before a
 * full PaymentIntent (destination account/tag, type, chain, expiry) exists.
 */
final readonly class PriceQuote
{
    /**
     * @param float|array{currency: string, value: string, issuer: string} $amountRequested
     *     float for XRP, an IssuedCurrencyAmount for stablecoins.
     */
    public function __construct(
        public string $baseAsset,
        public string $quoteCurrency,
        public string $pairing,
        public float $exchangeRate,
        public float|array $amountRequested,
    ) {
    }
}
