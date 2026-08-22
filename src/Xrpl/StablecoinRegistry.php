<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use InvalidArgumentException;

/**
 * Issuer/currency values for the stablecoins LedgerDirect accepts on XRPL.
 *
 * Security-critical: a wrong issuer address sends customer funds to a dead
 * trustline — real, unrecoverable loss. These values are verified once,
 * carefully, at authoring time against the Shopware ground truth and then
 * treated as fixed; see INVARIANTS.md for the provenance note. This class
 * intentionally has no dependency on, or reference to, any other repo.
 */
final class StablecoinRegistry
{
    public const RLUSD_CODE = 'RLUSD';

    /**
     * Quirk copied, not "corrected": the ground truth uses 'USD' here, not
     * 'USDC' — see INVARIANTS.md.
     */
    public const USDC_CODE = 'USD';

    private const RLUSD_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'currency' => '524C555344000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            'currency' => '524C555344000000000000000000000000000000',
        ],
    ];

    private const USDC_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            'currency' => '5553444300000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
            'currency' => '5553444300000000000000000000000000000000',
        ],
    ];

    /**
     * @param string $network 'mainnet' | 'testnet'
     * @param string $value decimal string, e.g. "12.34"
     * @return array{currency: string, value: string, issuer: string}
     */
    public function getRLUSDAmount(string $network, string $value): array
    {
        return self::buildAmount(self::RLUSD_SETTINGS, $network, $value);
    }

    /**
     * @param string $network 'mainnet' | 'testnet'
     * @param string $value decimal string, e.g. "12.34"
     * @return array{currency: string, value: string, issuer: string}
     */
    public function getUSDCAmount(string $network, string $value): array
    {
        return self::buildAmount(self::USDC_SETTINGS, $network, $value);
    }

    /**
     * @param array<string, array{issuer: string, currency: string}> $settings
     * @return array{currency: string, value: string, issuer: string}
     */
    private static function buildAmount(array $settings, string $network, string $value): array
    {
        if (!isset($settings[$network])) {
            throw new InvalidArgumentException("Unsupported network '{$network}'.");
        }

        return [
            'currency' => $settings[$network]['currency'],
            'value' => $value,
            'issuer' => $settings[$network]['issuer'],
        ];
    }
}
