<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LedgerAmountTest extends TestCase
{
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    #[DataProvider('drops')]
    public function testDropsBecomeTheNativeValueWithoutRounding(string|int $drops, string $value): void
    {
        $amount = LedgerAmount::fromWire($drops);

        self::assertTrue($amount->isNative());
        self::assertSame($value, $amount->value);
        self::assertNull($amount->currency);
        self::assertNull($amount->issuer);
    }

    /**
     * @return array<string, array{string|int, string}>
     */
    public static function drops(): array
    {
        return [
            'a whole amount' => ['6000000', '6'],
            'a fraction' => ['1070000', '1.07'],
            'one drop' => ['1', '0.000001'],
            'nothing' => ['0', '0'],
            // 0.1 + 0.2 as floats is 0.30000000000000004; as drops it is 300000.
            'a sum a float gets wrong' => ['300000', '0.3'],
            'beyond what a float holds exactly' => ['99999999999999999', '99999999999.999999'],
            'an integer instead of a string' => [1070000, '1.07'],
        ];
    }

    public function testAnIssuedCurrencyAmountKeepsCodeAndIssuerAndNormalisesTheValue(): void
    {
        $amount = LedgerAmount::fromWire(['currency' => self::RLUSD, 'issuer' => self::ISSUER, 'value' => '1.050']);

        self::assertFalse($amount->isNative());
        self::assertSame('1.05', $amount->value);
        self::assertSame(self::RLUSD, $amount->currency);
        self::assertSame(self::ISSUER, $amount->issuer);
    }

    public function testAValueInExponentNotationIsReadExactly(): void
    {
        self::assertSame('0.0000001', LedgerAmount::issued('USD', self::ISSUER, '1e-7')->value);
        self::assertSame('1500', LedgerAmount::issued('USD', self::ISSUER, '1.5e3')->value);
    }

    public function testAHexCurrencyCodeIsTheSameInEitherCaseAThreeLetterCodeIsNot(): void
    {
        $upper = LedgerAmount::issued(self::RLUSD, self::ISSUER, '1');
        $lower = LedgerAmount::issued(strtolower(self::RLUSD), self::ISSUER, '1');

        self::assertTrue($upper->sameAsset($lower));
        self::assertSame(self::RLUSD, $lower->currency);
        self::assertFalse(
            LedgerAmount::issued('USD', self::ISSUER, '1')->sameAsset(LedgerAmount::issued('usd', self::ISSUER, '1'))
        );
    }

    public function testTheSameCodeFromAnotherIssuerIsAnotherAsset(): void
    {
        $ours = LedgerAmount::issued(self::RLUSD, self::ISSUER, '1');
        $theirs = LedgerAmount::issued(self::RLUSD, 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', '1');

        self::assertFalse($ours->sameAsset($theirs));
        self::assertFalse($ours->equals($theirs));
    }

    public function testTheNativeAssetIsNeverAnIssuedOne(): void
    {
        $native = LedgerAmount::fromWire('1000000');
        $issued = LedgerAmount::issued(self::RLUSD, self::ISSUER, '1');

        self::assertFalse($native->sameAsset($issued));
        self::assertFalse($native->equals($issued));
        self::assertTrue($native->sameAsset(LedgerAmount::native('250')));
    }

    public function testEqualsIsExact(): void
    {
        $asked = LedgerAmount::native('1.07');

        self::assertTrue($asked->equals(LedgerAmount::fromWire('1070000')));
        self::assertTrue($asked->equals(LedgerAmount::native('1.070000')));
        self::assertFalse($asked->equals(LedgerAmount::fromWire('1070001')), 'one drop more is another amount');
        self::assertFalse($asked->equals(LedgerAmount::fromWire('1069999')), 'one drop less is another amount');
        self::assertTrue(
            LedgerAmount::issued('USD', self::ISSUER, '21.60')->equals(LedgerAmount::issued('USD', self::ISSUER, '21.6'))
        );
    }

    public function testANativeValueFinerThanADropIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LedgerAmount::native('1.0000001');
    }

    #[DataProvider('notAnAmount')]
    public function testWhatIsNotAnAmountIsRefused(mixed $wire): void
    {
        $this->expectException(InvalidArgumentException::class);

        LedgerAmount::fromWire($wire);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notAnAmount(): array
    {
        return [
            "the ledger's marker for an unknown delivered amount" => ['unavailable'],
            'negative drops' => ['-5'],
            'drops with a fraction' => ['1.5'],
            // `$` would let a final newline through; the whole string has to be digits.
            'drops followed by a newline' => ["1070000\n"],
            'a hex currency code followed by a newline' => [['currency' => self::RLUSD . "\n", 'issuer' => self::ISSUER, 'value' => '1']],
            'an empty string' => [''],
            'a float' => [1.5],
            'no issuer' => [['currency' => 'USD', 'value' => '1']],
            'an empty issuer' => [['currency' => 'USD', 'issuer' => '', 'value' => '1']],
            'a value that is not a number' => [['currency' => 'USD', 'issuer' => self::ISSUER, 'value' => 'ten']],
            'a negative value' => [['currency' => 'USD', 'issuer' => self::ISSUER, 'value' => '-1']],
            'a currency code of the wrong length' => [['currency' => 'USDC', 'issuer' => self::ISSUER, 'value' => '1']],
            'a Multi-Purpose Token amount' => [['mpt_issuance_id' => '0000012FFD9EE5DA93AC614B4DB94D7E0FCE415CA51BED47', 'value' => '1']],
            'nothing' => [null],
        ];
    }
}
