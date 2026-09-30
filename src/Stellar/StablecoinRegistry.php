<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use InvalidArgumentException;

/**
 * Issuer addresses for the stablecoins LedgerDirect accepts on Stellar.
 *
 * Security-critical: a wrong issuer sends customer funds to a trust line
 * the merchant does not hold — real, unrecoverable loss. The values below
 * were copied from Circle's developer documentation on 2026-09-30
 * (https://developers.circle.com/stablecoins/usdc-contract-addresses and
 * …/eurc-contract-addresses, rows "Stellar" and "Stellar Testnet"), never
 * typed from memory, and verified the same day against Horizon: each asset
 * exists on its network under exactly this issuer. The excerpt of the
 * source and the Horizon answers are checked in under
 * tests/Stellar/fixtures/registry/, and StablecoinRegistryTest compares
 * these constants with them. Touch a value only with the source open.
 *
 * Asset codes are plain text on Stellar; there is no hex encoding and no
 * XRPL-style `USDC_CODE = 'USD'` quirk.
 */
final class StablecoinRegistry
{
    public const USDC = 'USDC';
    public const EURC = 'EURC';

    private const ISSUERS = [
        self::USDC => [
            'mainnet' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'testnet' => 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5',
        ],
        self::EURC => [
            'mainnet' => 'GDHU6WRG4IEQXM5NZ4BMPKOXHW76MZM4Y2IEMFDVXBSDP6SJY4ITNPP2',
            'testnet' => 'GB3Q6QDZYTHWT7E5PVS3W7FUT5GVAFC5KSZFFLPU25GO7VTC3NM2ZTVO',
        ],
    ];

    /** @return list<string> the asset codes this registry knows */
    public static function codes(): array
    {
        return array_keys(self::ISSUERS);
    }

    public function knows(string $code): bool
    {
        return isset(self::ISSUERS[$code]);
    }

    public function issuer(string $code, string $network): string
    {
        return self::ISSUERS[$code][$network]
            ?? throw new InvalidArgumentException("No Stellar issuer for {$code} on '{$network}'.");
    }

    /**
     * The `{currency, value, issuer}` envelope a PaymentIntent stores for an
     * issued asset on Stellar.
     *
     * @param string $value decimal string, e.g. "12.34"
     * @return array{currency: string, value: string, issuer: string}
     */
    public function amount(string $code, string $network, string $value): array
    {
        return [
            'currency' => $code,
            'value' => $value,
            'issuer' => $this->issuer($code, $network),
        ];
    }
}
