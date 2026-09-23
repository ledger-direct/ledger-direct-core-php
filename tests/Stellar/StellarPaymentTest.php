<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StellarPaymentTest extends TestCase
{
    /**
     * From the M0 fixtures: the two operations of one transaction share the
     * hash and differ in op_index, and the TOID's upper 32 bits are the ledger.
     */
    public function testTheKeyIsHashAndOpIndexAndTheLedgerComesFromTheToid(): void
    {
        $first = $this->payment(paymentId: '20741084267155457', opIndex: 1);
        $second = $this->payment(paymentId: '20741084267155458', opIndex: 2);

        self::assertSame('9A5A2CF4:1', $first->key());
        self::assertSame('9A5A2CF4:2', $second->key());
        self::assertSame(4829160, $first->ledger());
        self::assertSame(4829160, $second->ledger());
    }

    /**
     * The only form a MEMO_TEXT is accepted in, and the form every identifier
     * is compared in: no sign, no spaces, no leading zeros, within uint64.
     */
    public function testCanonicalIdentifierForm(): void
    {
        foreach (['0', '1', '123456', '4294967295', '18446744073709551615'] as $ok) {
            self::assertTrue(StellarPayment::isCanonicalIdentifier($ok), $ok);
        }

        foreach (['', ' 123456', '123456 ', '0123', '+1', '-1', '1.0', '1e3', '18446744073709551616', 'abc'] as $bad) {
            self::assertFalse(StellarPayment::isCanonicalIdentifier($bad), var_export($bad, true));
        }
    }

    public function testAMemoIdOutsideTheCanonicalFormIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payment(memoId: '0042');
    }

    public function testANativeAmountHasNoIssuerAndAnIssuedOneMust(): void
    {
        self::assertNull($this->payment()->assetIssuer);
        self::assertSame('GDZN7JUZHAJEULYCSOQFON5DC26Z6XOH7AB45LIHLXQCQS5MFUW4QN5C', $this->payment(assetCode: 'TESTUSD', assetIssuer: 'GDZN7JUZHAJEULYCSOQFON5DC26Z6XOH7AB45LIHLXQCQS5MFUW4QN5C')->assetIssuer);

        $this->expectException(InvalidArgumentException::class);
        $this->payment(assetCode: 'USDC', assetIssuer: null);
    }

    private function payment(
        string $paymentId = '20741071382257665',
        int $opIndex = 1,
        ?string $memoId = '123456',
        string $assetCode = StellarPayment::ASSET_NATIVE,
        ?string $assetIssuer = null,
    ): StellarPayment {
        return new StellarPayment(
            network: 'testnet',
            paymentId: $paymentId,
            hash: '9A5A2CF4',
            opIndex: $opIndex,
            type: 'payment',
            account: 'GDZN7JUZHAJEULYCSOQFON5DC26Z6XOH7AB45LIHLXQCQS5MFUW4QN5C',
            destination: 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA',
            memoId: $memoId,
            assetCode: $assetCode,
            assetIssuer: $assetIssuer,
            amount: '1.5000000',
            createdAt: 1790169372,
            raw: [],
        );
    }
}
