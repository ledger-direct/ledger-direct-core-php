<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Stellar\StablecoinRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The registry against its source. A wrong issuer is unrecoverable loss,
 * so the constants are compared with a checked-in excerpt of Circle's
 * documentation and with Horizon's own answer for each asset — both
 * recorded on the day the values were copied.
 */
final class StablecoinRegistryTest extends TestCase
{
    public function testEveryIssuerMatchesTheCircleExcerpt(): void
    {
        $registry = new StablecoinRegistry();
        $rows = $this->excerptRows();

        self::assertCount(4, $rows);

        foreach ($rows as [$code, $network, $pair]) {
            [$pairCode, $issuer] = explode('-', $pair, 2);
            self::assertSame($code, $pairCode);
            self::assertSame($issuer, $registry->issuer($code, $network), "{$code} on {$network}");
        }
    }

    /**
     * Horizon's /assets answer for each (code, issuer) on its network: the
     * asset exists, under this issuer, and is held by a meaningful number
     * of accounts — not a look-alike somebody registered.
     */
    public function testEveryIssuerIsConfirmedByHorizon(): void
    {
        $registry = new StablecoinRegistry();

        foreach ($this->excerptRows() as [$code, $network]) {
            $answer = StellarFixtures::json("registry/{$network}-{$code}-asset.json");
            $records = $answer['_embedded']['records'] ?? [];

            self::assertCount(1, $records, "{$code} on {$network}");
            self::assertSame($code, $records[0]['asset_code']);
            self::assertSame($registry->issuer($code, $network), $records[0]['asset_issuer']);
            self::assertGreaterThan(1000, (int) ($records[0]['accounts']['authorized'] ?? 0), 'held by more than a handful of accounts');
        }
    }

    public function testTheEnvelopeIsPlainText(): void
    {
        $registry = new StablecoinRegistry();

        self::assertSame(
            ['currency' => 'USDC', 'value' => '12.34', 'issuer' => 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5'],
            $registry->amount('USDC', 'testnet', '12.34'),
        );
        self::assertSame(['USDC', 'EURC'], StablecoinRegistry::codes());
        self::assertTrue($registry->knows('EURC'));
        self::assertFalse($registry->knows('USDT0'));
    }

    public function testAnUnknownNetworkOrCodeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StablecoinRegistry())->issuer('USDC', 'futurenet');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function excerptRows(): array
    {
        $rows = [];
        foreach (explode("\n", StellarFixtures::body('registry/circle-excerpt.txt')) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $rows[] = explode(' ', $line);
        }

        return $rows;
    }
}
