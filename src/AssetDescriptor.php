<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

/**
 * One asset on one chain as the configuration screen sees it. See ChainCatalog.
 */
final readonly class AssetDescriptor
{
    /**
     * @param string $code the `base_asset` value, e.g. 'USDC'
     * @param string $label what the admin shows, e.g. 'USDC (Circle)'
     * @param bool $native the chain's native asset: a float in the record, no trust line, no issuer
     * @param int $displayDecimals the rounding a quote uses and a page shows — 5 for native, 2 for stablecoins
     * @param string $type the `type` value the intent service writes, e.g. 'stellar-usdc-payment'
     * @param string|null $pegCurrency the quote currency the price fast-path treats as 1:1, or null
     * @param array<string, string> $issuers issuer per network for an issued asset; empty for native.
     *     Display only — the issuer is never merchant-configurable.
     */
    public function __construct(
        public string $code,
        public string $label,
        public bool $native,
        public int $displayDecimals,
        public string $type,
        public ?string $pegCurrency,
        public array $issuers,
    ) {
    }

    /** An issued asset needs a trust line on the receiving account before it can arrive. */
    public function requiresTrustline(): bool
    {
        return !$this->native;
    }

    public function issuer(string $network): ?string
    {
        return $this->issuers[$network] ?? null;
    }
}
