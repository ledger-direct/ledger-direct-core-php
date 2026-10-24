<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

use InvalidArgumentException;

/**
 * One chain as the configuration screen sees it. See ChainCatalog.
 */
final readonly class ChainDescriptor
{
    /**
     * @param string $chain the value `PaymentIntent::chain` carries, e.g. 'XRPL'
     * @param string $label what the admin shows, e.g. 'XRP Ledger'
     * @param list<string> $networks 'mainnet' | 'testnet'
     * @param string $identifierLabel what the customer has to put on the payment: 'Destination tag', 'Memo ID'
     * @param string $accountPattern a format check for the receiving account — never a proof the
     *     account exists or that the merchant controls it
     * @param list<AssetDescriptor> $assets in the order an adapter should offer them
     */
    public function __construct(
        public string $chain,
        public string $label,
        public string $nativeAsset,
        public array $networks,
        public string $identifierLabel,
        public string $accountPattern,
        public array $assets,
    ) {
    }

    public function isValidAccount(string $account): bool
    {
        return preg_match($this->accountPattern, $account) === 1;
    }

    /** @return list<string> */
    public function assetCodes(): array
    {
        return array_map(static fn (AssetDescriptor $a): string => $a->code, $this->assets);
    }

    public function hasAsset(string $code): bool
    {
        foreach ($this->assets as $asset) {
            if ($asset->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function asset(string $code): AssetDescriptor
    {
        foreach ($this->assets as $asset) {
            if ($asset->code === $code) {
                return $asset;
            }
        }

        throw new InvalidArgumentException("Chain {$this->chain} has no asset '{$code}'.");
    }
}
