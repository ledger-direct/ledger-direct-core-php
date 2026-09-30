<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri as XrplPaymentUri;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentUri;
use PHPUnit\Framework\TestCase;

final class PaymentUriTest extends TestCase
{
    public function testAnXlmRequestCarriesDestinationAmountAndMemo(): void
    {
        $uri = PaymentUri::forIntent($this->intent(2.2), '2.2');

        self::assertSame(
            'web+stellar:pay?destination=' . StellarFixtures::MERCHANT . '&amount=2.2&memo=123456&memo_type=MEMO_ID',
            $uri,
        );
    }

    public function testAnIssuedAssetRequestNamesCodeAndIssuer(): void
    {
        $intent = $this->intent(['currency' => 'USDC', 'value' => '12.34', 'issuer' => 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5']);

        $uri = PaymentUri::forIntent($intent, '9');

        self::assertStringContainsString('&amount=9&asset_code=USDC&asset_issuer=GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5&memo=123456&memo_type=MEMO_ID', $uri);
    }

    public function testTheMemoIsAlwaysInTheRequestEvenWithoutAnAmount(): void
    {
        $uri = PaymentUri::forIntent($this->intent(2.2), '2.2', XrplPaymentUri::AMOUNT_NONE);

        self::assertStringNotContainsString('amount=', $uri);
        self::assertStringEndsWith('&memo=123456&memo_type=MEMO_ID', $uri);
    }

    /**
     * @param float|array{currency: string, value: string, issuer: string} $amount
     */
    private function intent(float|array $amount): PaymentIntent
    {
        $native = !is_array($amount);

        return PaymentIntent::quote(
            type: $native ? 'stellar-xlm-payment' : 'stellar-usdc-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: $native ? 'XLM' : $amount['currency'],
            quoteCurrency: 'USD',
            pairing: ($native ? 'XLM' : $amount['currency']) . '/USD',
            exchangeRate: 1.0,
            amountRequested: $amount,
            destinationAccount: StellarFixtures::MERCHANT,
            destinationTag: 123456,
        );
    }
}
