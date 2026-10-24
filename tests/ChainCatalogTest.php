<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService as XrplIntentService;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentIntentService as StellarIntentService;
use Hardcastle\LedgerDirect\Core\Stellar\StablecoinRegistry as StellarRegistry;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry as XrplRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChainCatalogTest extends TestCase
{
    public function testListsBothChainsWithTheirAssetsInOrder(): void
    {
        $chains = ChainCatalog::chains();

        self::assertSame(['XRPL', 'STELLAR'], array_map(static fn ($c): string => $c->chain, $chains));
        self::assertSame(['XRP', 'RLUSD', 'USDC'], ChainCatalog::chain('XRPL')->assetCodes());
        self::assertSame(['XLM', 'USDC', 'EURC'], ChainCatalog::chain('STELLAR')->assetCodes());
        self::assertSame(['mainnet', 'testnet'], ChainCatalog::chain('XRPL')->networks);
        self::assertSame('Destination tag', ChainCatalog::chain('XRPL')->identifierLabel);
        self::assertSame('Memo ID', ChainCatalog::chain('STELLAR')->identifierLabel);
        self::assertTrue(ChainCatalog::has('STELLAR'));
        self::assertFalse(ChainCatalog::has('XAHAU'));
    }

    /**
     * The catalogue is derived, not a second truth: every `type` it lists is
     * exactly what the intent service writes for that asset.
     */
    public function testTypesAgreeWithTheIntentServices(): void
    {
        $client = new FakeHttpClient();
        $this->queueNativePrice($client, 'ripple', 'XRPUSDT', 'XXRPZUSD');
        $xrpl = new XrplIntentService(
            new PriceService($client, new HttpFactory(), new RecordingLogger()),
            new DestinationTagService(new InMemoryXrplTransactionRepository()),
            new FakeConfigProvider(),
        );
        foreach (ChainCatalog::chain('XRPL')->assets as $asset) {
            self::assertSame($asset->type, $xrpl->quoteForOrder(10.0, 'USD', $asset->code)->type, $asset->code);
        }

        $client = new FakeHttpClient();
        $this->queueNativePrice($client, 'stellar', 'XLMUSDT', 'XXLMZUSD');
        $stellar = new StellarIntentService(
            new PriceService($client, new HttpFactory(), new RecordingLogger()),
            new MemoIdService(new InMemoryStellarPaymentRepository()),
            new FakeConfigProvider(),
        );
        foreach (ChainCatalog::chain('STELLAR')->assets as $asset) {
            $quoteCurrency = $asset->pegCurrency ?? 'USD';
            self::assertSame($asset->type, $stellar->quoteForOrder(10.0, $quoteCurrency, $asset->code)->type, $asset->code);
        }
    }

    public function testIssuersAgreeWithTheRegistries(): void
    {
        $xrplRegistry = new XrplRegistry();
        $stellarRegistry = new StellarRegistry();

        self::assertSame($xrplRegistry->getRLUSDAmount('mainnet', '1')['issuer'], ChainCatalog::chain('XRPL')->asset('RLUSD')->issuer('mainnet'));
        self::assertSame($xrplRegistry->getUSDCAmount('testnet', '1')['issuer'], ChainCatalog::chain('XRPL')->asset('USDC')->issuer('testnet'));
        self::assertSame($stellarRegistry->issuer('USDC', 'mainnet'), ChainCatalog::chain('STELLAR')->asset('USDC')->issuer('mainnet'));
        self::assertSame($stellarRegistry->issuer('EURC', 'testnet'), ChainCatalog::chain('STELLAR')->asset('EURC')->issuer('testnet'));
        self::assertNull(ChainCatalog::chain('XRPL')->asset('XRP')->issuer('mainnet'));
    }

    public function testNativeAssetsHaveNoTrustlineAndFivePlacesStablecoinsTwo(): void
    {
        foreach (ChainCatalog::chains() as $chain) {
            foreach ($chain->assets as $asset) {
                self::assertSame($asset->code === $chain->nativeAsset, $asset->native, "{$chain->chain} {$asset->code}");
                self::assertSame(!$asset->native, $asset->requiresTrustline());
                self::assertSame($asset->native ? 5 : 2, $asset->displayDecimals);
                self::assertSame($asset->native, $asset->pegCurrency === null);
            }
        }
    }

    public function testAccountFormatsTellTheChainsApart(): void
    {
        $xrpl = ChainCatalog::chain('XRPL');
        $stellar = ChainCatalog::chain('STELLAR');

        self::assertTrue($xrpl->isValidAccount('rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh'));
        self::assertFalse($xrpl->isValidAccount('GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA'));
        self::assertFalse($xrpl->isValidAccount('r0ILO'), 'base58 without 0, O, I, l');
        self::assertTrue($stellar->isValidAccount('GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA'));
        self::assertFalse($stellar->isValidAccount('rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh'));
        self::assertFalse($stellar->isValidAccount('MCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVMAAAAAAAACP36ET2E'), 'a muxed address is not a receiving account');
    }

    public function testForTypeFindsChainAndAsset(): void
    {
        [$chain, $asset] = ChainCatalog::forType('stellar-eurc-payment');

        self::assertSame('STELLAR', $chain->chain);
        self::assertSame('EURC', $asset->code);
        self::assertNull(ChainCatalog::forType('xahau-xah-payment'));
    }

    public function testUnknownChainOrAssetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChainCatalog::chain('XRPL')->asset('XAH');
    }

    private function queueNativePrice(FakeHttpClient $client, string $coingeckoId, string $binance, string $kraken): void
    {
        $client->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.5"}'));
        $client->queueResponse('api.coingecko.com', new Response(200, [], '{"' . $coingeckoId . '":{"usd":0.5}}'));
        $client->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"' . $kraken . '":{"c":["0.5","1"]}}}'));
    }
}
