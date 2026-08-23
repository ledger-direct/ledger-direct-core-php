<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;

/**
 * Test-only fake ConfigProviderInterface — everything is set via
 * constructor with sensible defaults, and mutable afterwards for tests that
 * need to change merchant config mid-scenario (e.g. simulating the
 * destination account being reconfigured).
 *
 * $chain is accepted on every interface method (matching the real
 * contract) but currently ignored — this fixture holds one global set of
 * values, since every current test scenario is XRPL-only. Genuinely
 * per-chain fixture state is a YAGNI call until a test actually needs to
 * distinguish chains.
 *
 * @internal
 */
final class FakeConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private string $network = 'testnet',
        private string $destinationAccount = 'rDestinationAccount',
        private bool $assetEnabled = true,
        private int $quoteExpirySeconds = 900,
    ) {
    }

    public function getNetwork(string $chain): string
    {
        return $this->network;
    }

    public function setNetwork(string $network): void
    {
        $this->network = $network;
    }

    public function getDestinationAccount(string $chain): string
    {
        return $this->destinationAccount;
    }

    public function setDestinationAccount(string $destinationAccount): void
    {
        $this->destinationAccount = $destinationAccount;
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        return $this->assetEnabled;
    }

    public function setAssetEnabled(bool $assetEnabled): void
    {
        $this->assetEnabled = $assetEnabled;
    }

    public function getQuoteExpirySeconds(): int
    {
        return $this->quoteExpirySeconds;
    }
}
