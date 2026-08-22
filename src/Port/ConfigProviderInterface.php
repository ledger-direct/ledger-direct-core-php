<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Port;

/**
 * Provided by the platform. Everything the core needs to know about how the
 * merchant has configured LedgerDirect, without depending on how or where
 * that configuration is stored.
 */
interface ConfigProviderInterface
{
    /**
     * @return string 'mainnet' | 'testnet'
     */
    public function getNetwork(): string;

    /**
     * The merchant's XRPL receiving wallet address for the active network.
     */
    public function getDestinationAccount(): string;

    /**
     * Whether payments in the given base asset (e.g. 'XRP', 'RLUSD', 'USDC')
     * are currently accepted.
     */
    public function isAssetEnabled(string $baseAsset): bool;

    /**
     * How long a price quote stays valid, in seconds.
     */
    public function getQuoteExpirySeconds(): int;
}
