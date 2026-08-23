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

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function setNetwork(string $network): void
    {
        $this->network = $network;
    }

    public function getDestinationAccount(): string
    {
        return $this->destinationAccount;
    }

    public function setDestinationAccount(string $destinationAccount): void
    {
        $this->destinationAccount = $destinationAccount;
    }

    public function isAssetEnabled(string $baseAsset): bool
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
