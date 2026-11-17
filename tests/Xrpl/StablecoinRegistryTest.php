<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StablecoinRegistryTest extends TestCase
{
    private StablecoinRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new StablecoinRegistry();
    }

    public function testRlusdCodeQuirk(): void
    {
        self::assertSame('RLUSD', StablecoinRegistry::RLUSD_CODE);
    }

    public function testUsdcCodeQuirk(): void
    {
        self::assertSame('USD', StablecoinRegistry::USDC_CODE);
    }

    public function testGetRlusdAmountOnMainnet(): void
    {
        $amount = $this->registry->getRLUSDAmount('mainnet', '12.34');

        self::assertSame([
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '12.34',
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
        ], $amount);
    }

    public function testGetRlusdAmountOnTestnet(): void
    {
        $amount = $this->registry->getRLUSDAmount('testnet', '5.00');

        self::assertSame([
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '5.00',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
        ], $amount);
    }

    public function testGetUsdcAmountOnMainnet(): void
    {
        $amount = $this->registry->getUSDCAmount('mainnet', '99.99');

        self::assertSame([
            'currency' => '5553444300000000000000000000000000000000',
            'value' => '99.99',
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
        ], $amount);
    }

    public function testGetUsdcAmountOnTestnet(): void
    {
        $amount = $this->registry->getUSDCAmount('testnet', '1.00');

        self::assertSame([
            'currency' => '5553444300000000000000000000000000000000',
            'value' => '1.00',
            'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
        ], $amount);
    }

    public function testGetRlusdAmountRejectsUnsupportedNetwork(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->getRLUSDAmount('devnet', '1.00');
    }

    public function testGetUsdcAmountRejectsUnsupportedNetwork(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->getUSDCAmount('', '1.00');
    }

    /**
     * The constants against the issuers' own documentation (Circle for USDC, Ripple for RLUSD),
     * checked in as a verbatim excerpt: a typo in the registry or in a later edit fails here.
     */
    public function testEveryIssuerMatchesTheIssuersDocumentation(): void
    {
        $rows = self::excerptRows();
        self::assertCount(4, $rows, 'RLUSD and USDC on mainnet and testnet');

        foreach ($rows as [$asset, $network, $currency, $issuer]) {
            $amount = $asset === 'RLUSD'
                ? $this->registry->getRLUSDAmount($network, '0')
                : $this->registry->getUSDCAmount($network, '0');

            self::assertSame($issuer, $amount['issuer'], "{$asset} {$network} issuer");
            self::assertSame($currency, $amount['currency'], "{$asset} {$network} currency");
        }
    }

    /**
     * The ledger agrees: each issuer has issued exactly that currency on its network
     * (gateway_balances, recorded on 2026-11-17 from xrplcluster.com and the testnet node).
     */
    public function testEveryIssuerHasIssuedItsCurrencyOnTheLedger(): void
    {
        foreach (self::excerptRows() as [$asset, $network, $currency, $issuer]) {
            $answer = json_decode(
                (string) file_get_contents(__DIR__ . "/fixtures/registry/{$network}-{$asset}-gateway-balances.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['result'];

            self::assertSame($issuer, $answer['account'], "{$asset} {$network}");
            self::assertSame([$currency], array_keys($answer['obligations']), "{$asset} {$network}: one currency, the registry's");
            self::assertGreaterThan(0, (float) $answer['obligations'][$currency], "{$asset} {$network}: in circulation");
        }
    }

    /**
     * @return list<array{string, string, string, string}> asset, network, currency hex, issuer
     */
    private static function excerptRows(): array
    {
        $rows = [];
        foreach (file(__DIR__ . '/fixtures/registry/issuer-excerpt.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$asset, $network, $pair] = explode(' ', $line);
            [$currency, $issuer] = explode('.', $pair);
            $rows[] = [$asset, $network, $currency, $issuer];
        }

        return $rows;
    }
}
