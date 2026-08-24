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
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class PriceService
{
    /**
     * The `v1` marker lets a change to the oracle set invalidate every
     * entry at once. Dots are PSR-16-legal key characters; `{}()/\@:` are
     * reserved and must not appear.
     */
    private const CACHE_KEY_PREFIX = 'ledger-direct.rate.v1';

    public const DEFAULT_FRESH_TTL_SECONDS = 60;

    /** How far past the fresh TTL a rate may still be served when every oracle is down. */
    private const STALE_MULTIPLIER = 5;

    private readonly StablecoinRegistry $stablecoinRegistry;

    /**
     * $rateCache is optional: without it the service behaves exactly as it
     * did before caching existed — every quote hits the oracles. The core
     * stores nothing itself; where the bytes land is the platform's
     * business, exactly as with XrplTransactionRepositoryInterface. The
     * cache must be shared across requests: a checkout render *is* a
     * request, so a per-process array would buy nothing.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $rateCache = null,
        private readonly int $freshTtlSeconds = self::DEFAULT_FRESH_TTL_SECONDS,
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

        $exchangeRate = $this->exchangeRate($provider, $baseAsset, $quoteCurrency, $network);

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
     * The rate for a pairing, cached when a cache was injected.
     *
     * Two tiers: a fresh entry (younger than $freshTtlSeconds) is served
     * outright, no oracle call. Beyond that the oracles are asked again —
     * and only if *they* fail does a stale entry get served, up to the
     * stale horizon. That middle tier is the real point of caching here:
     * without it a single oracle hiccup makes the payment method vanish
     * mid-checkout; with it, the rate is merely a minute old.
     *
     * Only the rate is cached, never a PriceQuote — the amount depends on
     * the order total, the rate doesn't. Failures are never cached: a
     * cached failure would block checkout.
     */
    private function exchangeRate(
        AbstractPriceProvider $provider,
        string $baseAsset,
        string $quoteCurrency,
        string $network,
    ): float {
        if ($this->rateCache === null) {
            return $this->fetchRate($provider, $baseAsset, $quoteCurrency);
        }

        $key = self::cacheKey($network, $baseAsset, $quoteCurrency);
        $entry = $this->readEntry($key);
        $now = time();

        if ($entry !== null && $now - $entry['fetched_at'] <= $this->freshTtlSeconds) {
            return $entry['rate'];
        }

        try {
            $rate = $this->fetchRate($provider, $baseAsset, $quoteCurrency);
        } catch (PriceUnavailableException $exception) {
            if ($entry !== null && $now - $entry['fetched_at'] <= $this->staleHorizonSeconds()) {
                $this->logger->warning('LedgerDirect: serving a stale exchange rate; no oracle was reachable', [
                    'pairing' => $baseAsset . '/' . $quoteCurrency,
                    'network' => $network,
                    'age_seconds' => $now - $entry['fetched_at'],
                    'reason' => $exception->getMessage(),
                ]);

                return $entry['rate'];
            }

            throw $exception;
        }

        $this->writeEntry($key, ['rate' => $rate, 'fetched_at' => $now]);

        return $rate;
    }

    private function fetchRate(AbstractPriceProvider $provider, string $baseAsset, string $quoteCurrency): float
    {
        $exchangeRate = $provider->getCurrentExchangeRate($quoteCurrency);

        if ($exchangeRate === false) {
            throw new PriceUnavailableException(
                "No usable price for {$baseAsset}/{$quoteCurrency}: all oracles failed or diverged."
            );
        }

        return $exchangeRate;
    }

    /**
     * A broken cache must never take the checkout down with it: an
     * unreachable Redis degrades to "no cache", not to "no payment". The
     * shape of what came back is validated too — a foreign or outdated
     * entry under the same key must read as a miss, not raise a TypeError.
     *
     * @return array{rate: float, fetched_at: int}|null null on a miss, an
     *         unusable entry, or a cache that threw
     */
    private function readEntry(string $key): ?array
    {
        try {
            $entry = $this->rateCache?->get($key);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: rate cache read failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null)) {
            return null;
        }

        /*
         * int is accepted alongside float on purpose: a backend that stores
         * the entry as JSON (PrestaShop's planned table does) hands a rate
         * of exactly 3.0 back as int 3, which would otherwise read as a
         * permanent miss for every whole-number rate.
         */
        $rate = $entry['rate'] ?? null;
        if (!is_float($rate) && !is_int($rate)) {
            return null;
        }

        return ['rate' => (float) $rate, 'fetched_at' => $entry['fetched_at']];
    }

    /**
     * Written with the *stale horizon* as the PSR-16 TTL, not the fresh
     * TTL: PSR-16 cannot hand back an expired value, so freshness is
     * carried inside the entry instead. There is no other way to do
     * stale-while-error on PSR-16.
     *
     * @param array{rate: float, fetched_at: int} $entry
     */
    private function writeEntry(string $key, array $entry): void
    {
        try {
            $this->rateCache?->set($key, $entry, $this->staleHorizonSeconds());
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: rate cache write failed', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function staleHorizonSeconds(): int
    {
        return $this->freshTtlSeconds * self::STALE_MULTIPLIER;
    }

    /**
     * The network must be part of the key, or mainnet and testnet rates
     * collide.
     */
    private static function cacheKey(string $network, string $baseAsset, string $quoteCurrency): string
    {
        return implode('.', [
            self::CACHE_KEY_PREFIX,
            $network,
            strtoupper($baseAsset),
            strtoupper($quoteCurrency),
        ]);
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
