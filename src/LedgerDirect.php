<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The composition root: every port in once, every service out.
 *
 * This is the one object a platform binds — a Laravel service provider
 * registers it as a singleton and a facade points at it, a Symfony bundle
 * declares it as one autowired service, a legacy module keeps it in a
 * static holder. Each service is built lazily on first request and then
 * memoised, so the order in which a platform asks for them does not
 * matter and nothing is constructed that is never used.
 *
 * The services stay publicly constructible as before; wiring them by hand
 * still works. What a platform gives up by doing so is the guarantee that
 * the wiring survives the next release — with create() a constructor
 * change touches one place instead of one per adapter.
 *
 * No static state, on purpose: a facade belongs to the platform bridge
 * that has a container to resolve it from.
 */
final class LedgerDirect
{
    private ?XrplClient $xrplClient = null;
    private ?PriceService $priceService = null;
    private ?SyncService $syncService = null;
    private ?DestinationTagService $destinationTagService = null;
    private ?PaymentIntentService $paymentIntentService = null;
    private ?SettlementPolicy $settlementPolicy = null;
    private ?SyncThrottle $syncThrottle = null;

    private function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly XrplTransactionRepositoryInterface $transactionRepository,
        private readonly ConfigProviderInterface $configProvider,
        private readonly ?CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $nativeAssetTolerance,
        private readonly int $rateFreshTtlSeconds,
    ) {
    }

    /**
     * @param CacheInterface|null $cache shared across requests; backs the rate cache and the sync
     *     throttle. Without one there is no caching and no throttling — exactly the pre-cache behaviour.
     * @param ClockInterface|null $clock defaults to the wall clock; inject a frozen one in tests
     * @param string $nativeAssetTolerance see SettlementPolicy
     * @param int $rateFreshTtlSeconds see PriceService
     */
    public static function create(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        LoggerInterface $logger,
        XrplTransactionRepositoryInterface $transactionRepository,
        ConfigProviderInterface $configProvider,
        ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
        string $nativeAssetTolerance = SettlementPolicy::DEFAULT_NATIVE_ASSET_TOLERANCE,
        int $rateFreshTtlSeconds = PriceService::DEFAULT_FRESH_TTL_SECONDS,
    ): self {
        return new self(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $logger,
            $transactionRepository,
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

    public function transactionRepository(): XrplTransactionRepositoryInterface
    {
        return $this->transactionRepository;
    }

    public function xrplClient(): XrplClient
    {
        return $this->xrplClient ??= new XrplClient($this->httpClient, $this->requestFactory, $this->streamFactory);
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
        return $this->syncService ??= new SyncService($this->xrplClient(), $this->transactionRepository, $this->logger);
    }

    public function destinationTagService(): DestinationTagService
    {
        return $this->destinationTagService ??= new DestinationTagService($this->transactionRepository);
    }

    public function paymentIntentService(): PaymentIntentService
    {
        return $this->paymentIntentService ??= new PaymentIntentService(
            $this->priceService(),
            $this->destinationTagService(),
            $this->configProvider,
            $this->clock,
        );
    }

    public function settlementPolicy(): SettlementPolicy
    {
        return $this->settlementPolicy ??= new SettlementPolicy($this->nativeAssetTolerance);
    }

    /**
     * Null without a cache: a per-process throttle would be useless, since
     * the status endpoint's call *is* the process.
     */
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

    /**
     * The status payload for an intent, judged by this root's policy and
     * clock — what a status endpoint serializes.
     */
    public function paymentStatus(PaymentIntent $intent): PaymentStatus
    {
        return PaymentStatus::fromIntent($intent, $this->settlementPolicy(), $this->clock->now()->getTimestamp());
    }
}
