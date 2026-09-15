<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\Fulfillment;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FulfillmentTest extends TestCase
{
    public function testHashAndCtidComeFromTheNewestContributingTransaction(): void
    {
        $fulfillment = new Fulfillment([$this->transaction('HASH_NEW', 'CTID_NEW'), $this->transaction('HASH_OLD', 'CTID_OLD')], 1.0);

        self::assertSame('HASH_NEW', $fulfillment->hash());
        self::assertSame('CTID_NEW', $fulfillment->ctid());
        self::assertSame(2, $fulfillment->count());
    }

    public function testApplyToPutsAllThreeValuesOnTheIntentWithoutMutatingIt(): void
    {
        $intent = $this->xrpIntent();
        $fulfilled = (new Fulfillment([$this->transaction('HASH', 'CTID')], 0.5))->applyTo($intent);

        self::assertNull($intent->hash);
        self::assertSame('HASH', $fulfilled->hash);
        self::assertSame('CTID', $fulfilled->ctid);
        self::assertSame(0.5, $fulfilled->amountPaid);
    }

    public function testAFulfillmentWithoutTransactionsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Fulfillment([], 1.0);
    }

    private function transaction(string $hash, string $ctid): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '1',
            hash: $hash,
            ctid: $ctid,
            account: 'rSender',
            destination: 'rDest',
            destinationTag: 1,
            date: 800000000,
            meta: [],
            tx: [],
        );
    }

    private function xrpIntent(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: 1.0,
            destinationAccount: 'rDest',
            destinationTag: 1,
        );
    }
}
