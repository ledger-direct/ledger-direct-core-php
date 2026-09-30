<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The Stellar composition root — the sibling of Core\LedgerDirect. A
 * platform that accepts both chains holds both roots and passes the same
 * cross-cutting objects (HTTP client, logger, config, cache, clock) into
 * each; what differs is the repository port and the chain services behind
 * the getters. Lazily built, memoised, no static state.
 */
final class LedgerDirectStellar
{
    private ?HorizonClient $horizonClient = null;
    private ?PriceService $priceService = null;
    private ?SyncService $syncService = null;
    private ?MemoIdService $memoIdService = null;
    private ?PaymentIntentService $paymentIntentService = null;
    private ?SettlementPolicy $settlementPolicy = null;
    private ?SyncThrottle $syncThrottle = null;

    private function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly StellarPaymentRepositoryInterface $paymentRepository,
        private readonly ConfigProviderInterface $configProvider,
        private readonly ?CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $nativeAssetTolerance,
        private readonly int $rateFreshTtlSeconds,
    ) {
    }

    public static function create(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        LoggerInterface $logger,
        StellarPaymentRepositoryInterface $paymentRepository,
        ConfigProviderInterface $configProvider,
        ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
        string $nativeAssetTolerance = SettlementPolicy::DEFAULT_NATIVE_ASSET_TOLERANCE,
        int $rateFreshTtlSeconds = PriceService::DEFAULT_FRESH_TTL_SECONDS,
    ): self {
        return new self(
            $httpClient,
            $requestFactory,
            $logger,
            $paymentRepository,
            $configProvider,
            $cache,
            $clock ?? new SystemClock(),
            $nativeAssetTolerance,
            $rateFreshTtlSeconds,
        );
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function configProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function paymentRepository(): StellarPaymentRepositoryInterface
    {
        return $this->paymentRepository;
    }

    public function horizonClient(): HorizonClient
    {
        return $this->horizonClient ??= new HorizonClient($this->httpClient, $this->requestFactory);
    }

    public function priceService(): PriceService
    {
        return $this->priceService ??= new PriceService(
            $this->httpClient,
            $this->requestFactory,
            $this->logger,
            $this->cache,
            $this->rateFreshTtlSeconds,
            $this->clock,
        );
    }

    public function syncService(): SyncService
    {
        return $this->syncService ??= new SyncService($this->horizonClient(), $this->paymentRepository, $this->logger);
    }

    public function memoIdService(): MemoIdService
    {
        return $this->memoIdService ??= new MemoIdService($this->paymentRepository);
    }

    public function paymentIntentService(): PaymentIntentService
    {
        return $this->paymentIntentService ??= new PaymentIntentService(
            $this->priceService(),
            $this->memoIdService(),
            $this->configProvider,
            $this->clock,
        );
    }

    public function settlementPolicy(): SettlementPolicy
    {
        return $this->settlementPolicy ??= new SettlementPolicy($this->nativeAssetTolerance);
    }

    /** Null without a cache — see Core\LedgerDirect::syncThrottle(). */
    public function syncThrottle(): ?SyncThrottle
    {
        if ($this->cache === null) {
            return null;
        }

        return $this->syncThrottle ??= new SyncThrottle(
            $this->cache,
            $this->logger,
            PaymentStatus::MIN_SYNC_INTERVAL_SECONDS,
            $this->clock,
        );
    }

    public function paymentStatus(PaymentIntent $intent): PaymentStatus
    {
        return PaymentStatus::fromIntent($intent, $this->settlementPolicy(), $this->clock->now()->getTimestamp());
    }
}
