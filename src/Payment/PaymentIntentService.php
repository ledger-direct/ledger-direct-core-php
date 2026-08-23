<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use InvalidArgumentException;

/**
 * Composes PriceService + ConfigProviderInterface + DestinationTagService
 * into a full PaymentIntent for an order — the piece CLAUDE.md doesn't name
 * on its own. Not a 1:1 port of anything in ground truth; that logic is
 * entangled with Shopware entities there.
 */
final class PaymentIntentService
{
    private const CHAIN = 'XRPL';

    private const TYPE_BY_ASSET = [
        'XRP' => 'xrp-payment',
        'RLUSD' => 'rlusd-payment',
        'USDC' => 'usdc-payment',
    ];

    public function __construct(
        private readonly PriceService $priceService,
        private readonly DestinationTagService $destinationTagService,
        private readonly ConfigProviderInterface $configProvider,
    ) {
    }

    /**
     * Builds a fresh PaymentIntent for an order, or refreshes the price on
     * an existing one while keeping its destination account/tag stable —
     * pass the order's current PaymentIntent (if it has one yet) as
     * $existing. Always recomputes price/rate/amount/expiry; only reuses
     * destinationAccount/destinationTag, and only when $existing's
     * destinationAccount still matches the merchant's current configured
     * one (handles the merchant having reconfigured it since).
     */
    public function quoteForOrder(
        float $total,
        string $quoteCurrency,
        string $baseAsset,
        ?PaymentIntent $existing = null,
    ): PaymentIntent {
        if (!$this->configProvider->isAssetEnabled(self::CHAIN, $baseAsset)) {
            throw new AssetNotAcceptedException("Payments in {$baseAsset} are not currently accepted.");
        }

        $network = $this->configProvider->getNetwork(self::CHAIN);
        $priceQuote = $this->priceService->getCryptoPriceForOrder($total, $quoteCurrency, $baseAsset, $network);
        [$destinationAccount, $destinationTag] = $this->resolveDestination($existing);

        return PaymentIntent::quote(
            type: self::TYPE_BY_ASSET[$baseAsset]
                ?? throw new InvalidArgumentException("Unsupported base_asset '{$baseAsset}'."),
            chain: self::CHAIN,
            network: $network,
            baseAsset: $priceQuote->baseAsset,
            quoteCurrency: $priceQuote->quoteCurrency,
            pairing: $priceQuote->pairing,
            exchangeRate: $priceQuote->exchangeRate,
            amountRequested: $priceQuote->amountRequested,
            destinationAccount: $destinationAccount,
            destinationTag: $destinationTag,
            expiry: time() + $this->configProvider->getQuoteExpirySeconds(),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveDestination(?PaymentIntent $existing): array
    {
        $currentDestinationAccount = $this->configProvider->getDestinationAccount(self::CHAIN);

        if ($existing !== null && $existing->destinationAccount === $currentDestinationAccount) {
            return [$existing->destinationAccount, $existing->destinationTag];
        }

        return [
            $currentDestinationAccount,
            $this->destinationTagService->generateDestinationTag($currentDestinationAccount),
        ];
    }
}
