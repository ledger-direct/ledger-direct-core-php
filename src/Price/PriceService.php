<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

final class PriceService
{
    private readonly StablecoinRegistry $stablecoinRegistry;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->stablecoinRegistry = new StablecoinRegistry();
    }

    /**
     * Fills the quote fragment of a payment — asset, currency, pairing,
     * rate, requested amount. Has no destination-account/tag inputs, so it
     * cannot build a full PaymentIntent by itself; see INVARIANTS.md.
     */
    public function getCryptoPriceForOrder(
        float $total,
        string $quoteCurrency,
        string $baseAsset,
        string $network,
    ): PriceQuote {
        [$provider, $roundPlaces] = $this->providerFor($baseAsset);

        $exchangeRate = $provider->getCurrentExchangeRate($quoteCurrency);
        if ($exchangeRate === false) {
            throw new PriceUnavailableException(
                "No usable price for {$baseAsset}/{$quoteCurrency}: all oracles failed or diverged."
            );
        }

        $amount = BigDecimal::of((string) $total)
            ->dividedBy((string) $exchangeRate, $roundPlaces, RoundingMode::HALF_UP);

        $amountRequested = match ($baseAsset) {
            XrpPriceProvider::CRYPTO_CODE => $amount->toFloat(),
            RlusdPriceProvider::CRYPTO_CODE => $this->stablecoinRegistry->getRLUSDAmount($network, (string) $amount),
            UsdcPriceProvider::CRYPTO_CODE => $this->stablecoinRegistry->getUSDCAmount($network, (string) $amount),
        };

        return new PriceQuote(
            baseAsset: $baseAsset,
            quoteCurrency: $quoteCurrency,
            pairing: $baseAsset . '/' . $quoteCurrency,
            exchangeRate: $exchangeRate,
            amountRequested: $amountRequested,
        );
    }

    /**
     * @return array{0: AbstractPriceProvider, 1: int}
     */
    private function providerFor(string $baseAsset): array
    {
        return match ($baseAsset) {
            XrpPriceProvider::CRYPTO_CODE => [
                new XrpPriceProvider($this->httpClient, $this->requestFactory, $this->logger),
                XrpPriceProvider::ROUND_PLACES,
            ],
            RlusdPriceProvider::CRYPTO_CODE => [
                new RlusdPriceProvider($this->httpClient, $this->requestFactory, $this->logger),
                RlusdPriceProvider::ROUND_PLACES,
            ],
            UsdcPriceProvider::CRYPTO_CODE => [
                new UsdcPriceProvider($this->httpClient, $this->requestFactory, $this->logger),
                UsdcPriceProvider::ROUND_PLACES,
            ],
            default => throw new InvalidArgumentException("Unsupported base_asset '{$baseAsset}'."),
        };
    }
}
