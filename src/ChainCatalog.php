<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

use Hardcastle\LedgerDirect\Core\Stellar\StablecoinRegistry as StellarRegistry;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry as XrplRegistry;
use InvalidArgumentException;

/**
 * What the core can do, described as data: the chains, their networks,
 * the assets it can price and match on each, the account format, the
 * name of the identifier a customer has to put on a payment. The one
 * place an adapter's configuration screen reads from, so four platforms
 * render the same structure — one collapsed panel per chain, one row per
 * asset — instead of hand-coding every chain four times. A chain the core
 * gains later shows up in the admin without an adapter release, as long
 * as it brings no new port.
 *
 * Everything here is derived from what the services already know: the
 * type names the intent services write, the issuers the registries hold,
 * the rounding the price providers use. Tests pin the agreement.
 */
final class ChainCatalog
{
    public const XRPL = 'XRPL';
    public const STELLAR = 'STELLAR';

    /** @var list<ChainDescriptor>|null */
    private static ?array $chains = null;

    /** @return list<ChainDescriptor> in the order an adapter should show them */
    public static function chains(): array
    {
        return self::$chains ??= [self::xrpl(), self::stellar()];
    }

    public static function has(string $chain): bool
    {
        foreach (self::chains() as $descriptor) {
            if ($descriptor->chain === $chain) {
                return true;
            }
        }

        return false;
    }

    public static function chain(string $chain): ChainDescriptor
    {
        foreach (self::chains() as $descriptor) {
            if ($descriptor->chain === $chain) {
                return $descriptor;
            }
        }

        throw new InvalidArgumentException("Unknown chain '{$chain}'.");
    }

    /** The descriptor that writes the given intent `type`, or null. */
    public static function forType(string $type): ?array
    {
        foreach (self::chains() as $chain) {
            foreach ($chain->assets as $asset) {
                if ($asset->type === $type) {
                    return [$chain, $asset];
                }
            }
        }

        return null;
    }

    private static function xrpl(): ChainDescriptor
    {
        $registry = new XrplRegistry();

        return new ChainDescriptor(
            chain: self::XRPL,
            label: 'XRP Ledger',
            nativeAsset: 'XRP',
            networks: ['mainnet', 'testnet'],
            identifierLabel: 'Destination tag',
            // Ripple's base58 alphabet omits 0, O, I and l.
            accountPattern: '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/',
            assets: [
                new AssetDescriptor('XRP', 'XRP', native: true, displayDecimals: 5, type: 'xrp-payment', pegCurrency: null, issuers: []),
                new AssetDescriptor('RLUSD', 'RLUSD (Ripple USD)', native: false, displayDecimals: 2, type: 'rlusd-payment', pegCurrency: 'USD', issuers: [
                    'mainnet' => $registry->getRLUSDAmount('mainnet', '0')['issuer'],
                    'testnet' => $registry->getRLUSDAmount('testnet', '0')['issuer'],
                ]),
                new AssetDescriptor('USDC', 'USDC (Circle)', native: false, displayDecimals: 2, type: 'usdc-payment', pegCurrency: 'USD', issuers: [
                    'mainnet' => $registry->getUSDCAmount('mainnet', '0')['issuer'],
                    'testnet' => $registry->getUSDCAmount('testnet', '0')['issuer'],
                ]),
            ],
        );
    }

    private static function stellar(): ChainDescriptor
    {
        $registry = new StellarRegistry();

        return new ChainDescriptor(
            chain: self::STELLAR,
            label: 'Stellar',
            nativeAsset: 'XLM',
            networks: ['mainnet', 'testnet'],
            identifierLabel: 'Memo ID',
            // Stellar public keys are StrKey: G plus 55 base32 characters.
            accountPattern: '/^G[A-Z2-7]{55}$/',
            assets: [
                new AssetDescriptor('XLM', 'XLM', native: true, displayDecimals: 5, type: 'stellar-xlm-payment', pegCurrency: null, issuers: []),
                new AssetDescriptor('USDC', 'USDC (Circle)', native: false, displayDecimals: 2, type: 'stellar-usdc-payment', pegCurrency: 'USD', issuers: [
                    'mainnet' => $registry->issuer(StellarRegistry::USDC, 'mainnet'),
                    'testnet' => $registry->issuer(StellarRegistry::USDC, 'testnet'),
                ]),
                new AssetDescriptor('EURC', 'EURC (Circle)', native: false, displayDecimals: 2, type: 'stellar-eurc-payment', pegCurrency: 'EUR', issuers: [
                    'mainnet' => $registry->issuer(StellarRegistry::EURC, 'mainnet'),
                    'testnet' => $registry->issuer(StellarRegistry::EURC, 'testnet'),
                ]),
            ],
        );
    }
}
