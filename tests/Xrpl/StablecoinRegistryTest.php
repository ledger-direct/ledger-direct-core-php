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
}
