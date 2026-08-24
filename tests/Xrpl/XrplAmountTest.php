<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class XrplAmountTest extends TestCase
{
    public function testConvertsDropsToXrp(): void
    {
        self::assertSame(15.06378, XrplAmount::dropsToXrp('15063780'));
        self::assertSame(1.0, XrplAmount::dropsToXrp('1000000'));
        self::assertSame(0.000001, XrplAmount::dropsToXrp('1'));
        self::assertSame(0.0, XrplAmount::dropsToXrp('0'));
    }

    public function testAcceptsDropsAsInteger(): void
    {
        self::assertSame(15.06378, XrplAmount::dropsToXrp(15063780));
    }

    /**
     * A drops value with a decimal point or a sign is not something the
     * ledger ever sends — it means the caller handed over XRP by mistake.
     */
    public function testRejectsNonIntegerDrops(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XrplAmount::dropsToXrp('15.06378');
    }

    public function testRejectsNegativeDrops(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XrplAmount::dropsToXrp('-1000000');
    }

    public function testConvertsXrpToDrops(): void
    {
        self::assertSame('15063780', XrplAmount::xrpToDrops(15.06378));
        self::assertSame('1000000', XrplAmount::xrpToDrops('1'));
        self::assertSame('1', XrplAmount::xrpToDrops('0.000001'));
        self::assertSame('0', XrplAmount::xrpToDrops(0.0));
    }

    public function testRoundTripsThroughDrops(): void
    {
        self::assertSame(12.34567, XrplAmount::dropsToXrp(XrplAmount::xrpToDrops(12.34567)));
    }

    /**
     * Refuses to silently round away a fraction of a drop rather than
     * quoting an amount the ledger cannot represent.
     */
    public function testRejectsAnXrpAmountFinerThanOneDrop(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XrplAmount::xrpToDrops('0.0000001');
    }

    public function testRejectsNegativeXrp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XrplAmount::xrpToDrops('-1');
    }

    public function testDecodesADropsStringAsXrp(): void
    {
        self::assertSame(15.06378, XrplAmount::decode('15063780'));
    }

    public function testDecodesAnIssuedCurrencyAmountUnchanged(): void
    {
        $registry = new StablecoinRegistry();
        $amount = $registry->getRLUSDAmount('mainnet', '12.34');

        self::assertSame($amount, XrplAmount::decode($amount));
    }

    public function testDecodeNormalizesIssuedCurrencyValuesToStrings(): void
    {
        self::assertSame(
            ['currency' => 'USD', 'value' => '12.34', 'issuer' => 'rIssuer'],
            XrplAmount::decode(['currency' => 'USD', 'value' => 12.34, 'issuer' => 'rIssuer']),
        );
    }

    public function testDecodeRejectsAnIncompleteIssuedCurrencyAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XrplAmount::decode(['currency' => 'USD', 'value' => '12.34']);
    }
}
