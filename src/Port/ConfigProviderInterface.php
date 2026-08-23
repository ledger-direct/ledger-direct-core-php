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
     * @param string $chain e.g. 'XRPL' — explicit because "network" alone is
     *     ambiguous once more than one chain exists (mainnet means something
     *     different per chain, and a merchant may run testnet on one chain
     *     while live on another).
     * @return string 'mainnet' | 'testnet'
     */
    public function getNetwork(string $chain): string;

    /**
     * The merchant's receiving wallet address on $chain, for the active
     * network. Not interchangeable across chains — an XRPL `r...` address
     * and a Stellar `G...` address are different formats entirely, so a
     * merchant configures one per chain, not one globally.
     */
    public function getDestinationAccount(string $chain): string;

    /**
     * Whether payments in the given base asset (e.g. 'XRP', 'RLUSD', 'USDC')
     * on $chain are currently accepted.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool;

    /**
     * How long a price quote stays valid, in seconds.
     */
    public function getQuoteExpirySeconds(): int;
}
