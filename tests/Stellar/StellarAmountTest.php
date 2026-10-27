<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Stellar\StellarAmount;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StellarAmountTest extends TestCase
{
    public function testDecodesNativeToAFloatAndIssuedToThePlainEnvelope(): void
    {
        self::assertSame(1.5, StellarAmount::decode($this->payment('1.5000000')));
        self::assertSame(
            ['currency' => 'TESTUSD', 'value' => '12.34', 'issuer' => StellarFixtures::PAYER],
            StellarAmount::decode($this->payment('12.3400000', 'TESTUSD', StellarFixtures::PAYER)),
            'trailing zeros dropped, code in plain text',
        );
    }

    public function testToWireKeepsUpToSevenPlacesAndRejectsSubStroopAmounts(): void
    {
        self::assertSame('2.2', StellarAmount::toWire(2.2));
        self::assertSame('0.0000001', StellarAmount::toWire('0.0000001'));
        self::assertSame('1', StellarAmount::toWire('1.0000000'));

        $this->expectException(InvalidArgumentException::class);
        StellarAmount::toWire('0.00000001');
    }

    private function payment(string $amount, string $code = 'XLM', ?string $issuer = null): StellarPayment
    {
        return new StellarPayment(
            network: 'testnet',
            paymentId: '20741071382257665',
            hash: 'H',
            opIndex: 1,
            type: 'payment',
            account: StellarFixtures::PAYER,
            destination: StellarFixtures::MERCHANT,
            memoId: '123456',
            assetCode: $code,
            assetIssuer: $issuer,
            amount: $amount,
            createdAt: 1790169372,
            raw: [],
        );
    }
}
