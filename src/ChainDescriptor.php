<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

use Hardcastle\LedgerDirect\Core\Identity\AccountEncoding;
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
     * @param AccountEncoding|null $accountEncoding decodes the account and verifies its checksum,
     *     on top of the pattern; null for a chain whose addresses carry none
     */
    public function __construct(
        public string $chain,
        public string $label,
        public string $nativeAsset,
        public array $networks,
        public string $identifierLabel,
        public string $accountPattern,
        public array $assets,
        public ?AccountEncoding $accountEncoding = null,
    ) {
    }

    /**
     * Whether $account can be a receiving account on this chain: the format
     * matches **and** the address decodes with a valid checksum. A mistyped
     * character from the right alphabet passes the pattern but not the
     * checksum — that is the case this exists for. Still never a proof that
     * the account exists or that the merchant controls it.
     */
    public function isValidAccount(string $account): bool
    {
        return preg_match($this->accountPattern, $account) === 1
            && ($this->accountEncoding === null || $this->accountEncoding->isValid($account));
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
