<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

/**
 * What an issuer can do to balances in its asset, read from the issuer
 * account's flags. Circle's USDC and EURC are `auth_revocable` on mainnet
 * (verified 2026-09-30, fixtures under tests/Stellar/fixtures/registry/):
 * the issuer can freeze a trust line. Neither has clawback enabled.
 */
final readonly class IssuerFlags
{
    public function __construct(
        public bool $authRequired,
        public bool $authRevocable,
        public bool $clawbackEnabled,
    ) {
    }

    /** Whether the issuer can freeze or take back what the merchant holds — what the admin one-liner says. */
    public function canFreezeOrClawBack(): bool
    {
        return $this->authRevocable || $this->clawbackEnabled;
    }
}
