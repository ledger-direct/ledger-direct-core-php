<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Composes PriceService, ConfigProviderInterface and MemoIdService into a
 * full Stellar PaymentIntent for an order — the sibling of
 * Payment\PaymentIntentService, with the same quote-and-refresh behaviour
 * and Stellar's names (INVARIANTS.md, "Stellar", Names in the record).
 * XLM is a float; USDC and EURC get the plain-text envelope from the
 * Stellar registry.
 */
final class PaymentIntentService
{
    public const CHAIN = 'STELLAR';

    private const TYPE_BY_ASSET = [
        'XLM' => 'stellar-xlm-payment',
        StablecoinRegistry::USDC => 'stellar-usdc-payment',
        StablecoinRegistry::EURC => 'stellar-eurc-payment',
    ];

    private readonly ClockInterface $clock;

    private readonly StablecoinRegistry $registry;

    public function __construct(
        private readonly PriceService $priceService,
        private readonly MemoIdService $memoIdService,
        private readonly ConfigProviderInterface $configProvider,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->registry = new StablecoinRegistry();
    }

    /**
     * Builds a fresh intent, or re-quotes an existing one while keeping its
     * receiving account and memo id — the memo is what ties an incoming
     * payment to this order, so a refresh must not reallocate it.
     */
    public function quoteForOrder(
        float $total,
        string $quoteCurrency,
        string $baseAsset,
        ?PaymentIntent $existing = null,
    ): PaymentIntent {
        if (!$this->configProvider->isAssetEnabled(self::CHAIN, $baseAsset)) {
            throw new AssetNotAcceptedException("Payments in {$baseAsset} on Stellar are not currently accepted.");
        }

        $type = self::TYPE_BY_ASSET[$baseAsset]
            ?? throw new InvalidArgumentException("Unsupported Stellar base_asset '{$baseAsset}'.");

        $network = $this->configProvider->getNetwork(self::CHAIN);
        $quote = $this->priceService->quoteAmount($total, $quoteCurrency, $baseAsset, $network);
        [$destinationAccount, $memoId] = $this->resolveDestination($existing);

        return PaymentIntent::quote(
            type: $type,
            chain: self::CHAIN,
            network: $network,
            baseAsset: $quote->baseAsset,
            quoteCurrency: $quote->quoteCurrency,
            pairing: $quote->pairing,
            exchangeRate: $quote->exchangeRate,
            amountRequested: $baseAsset === 'XLM'
                ? (float) $quote->amount
                : $this->registry->amount($baseAsset, $network, $quote->amount),
            destinationAccount: $destinationAccount,
            destinationTag: $memoId,
            expiry: $this->clock->now()->getTimestamp() + $this->configProvider->getQuoteExpirySeconds(),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveDestination(?PaymentIntent $existing): array
    {
        $currentAccount = $this->configProvider->getDestinationAccount(self::CHAIN);

        if ($existing !== null && $existing->destinationAccount === $currentAccount) {
            return [$existing->destinationAccount, $existing->destinationTag];
        }

        return [$currentAccount, $this->memoIdService->generateMemoId($currentAccount)];
    }
}
