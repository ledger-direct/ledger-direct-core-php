<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Composes PriceService + ConfigProviderInterface + DestinationTagService
 * into a full PaymentIntent for an order — the piece CLAUDE.md doesn't name
 * on its own. Not a 1:1 port of anything in ground truth; that logic is
 * entangled with Shopware entities there.
 *
 * Serves the XRP Ledger and Xahau, which share the record's shape: a
 * destination tag, a native amount as a number, an issued one as an
 * IssuedCurrencyAmount. The chain is fixed per instance.
 */
final class PaymentIntentService
{
    private const TYPE_BY_CHAIN_AND_ASSET = [
        'XRPL' => [
            'XRP' => 'xrp-payment',
            'RLUSD' => 'rlusd-payment',
            'USDC' => 'usdc-payment',
        ],
        'XAHAU' => [
            'XAH' => 'xahau-xah-payment',
        ],
    ];

    private readonly ClockInterface $clock;

    /**
     * @param ClockInterface|null $clock what "now" is for the quote's expiry; the wall clock by
     *     default, a frozen one in tests
     * @param string $chain 'XRPL' or 'XAHAU'
     */
    public function __construct(
        private readonly PriceService $priceService,
        private readonly DestinationTagService $destinationTagService,
        private readonly ConfigProviderInterface $configProvider,
        ?ClockInterface $clock = null,
        private readonly string $chain = 'XRPL',
    ) {
        if (!isset(self::TYPE_BY_CHAIN_AND_ASSET[$chain])) {
            throw new InvalidArgumentException("Unsupported chain '{$chain}'.");
        }

        $this->clock = $clock ?? new SystemClock();
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
        if (!$this->configProvider->isAssetEnabled($this->chain, $baseAsset)) {
            throw new AssetNotAcceptedException("Payments in {$baseAsset} are not currently accepted.");
        }

        $type = self::TYPE_BY_CHAIN_AND_ASSET[$this->chain][$baseAsset]
            ?? throw new InvalidArgumentException("Unsupported base_asset '{$baseAsset}'.");

        $network = $this->configProvider->getNetwork($this->chain);
        $priceQuote = $this->priceService->getCryptoPriceForOrder($total, $quoteCurrency, $baseAsset, $network);
        [$destinationAccount, $destinationTag] = $this->resolveDestination($existing);

        return PaymentIntent::quote(
            type: $type,
            chain: $this->chain,
            network: $network,
            baseAsset: $priceQuote->baseAsset,
            quoteCurrency: $priceQuote->quoteCurrency,
            pairing: $priceQuote->pairing,
            exchangeRate: $priceQuote->exchangeRate,
            amountRequested: $priceQuote->amountRequested,
            destinationAccount: $destinationAccount,
            destinationTag: $destinationTag,
            expiry: $this->clock->now()->getTimestamp() + $this->configProvider->getQuoteExpirySeconds(),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveDestination(?PaymentIntent $existing): array
    {
        $currentDestinationAccount = $this->configProvider->getDestinationAccount($this->chain);

        if ($existing !== null && $existing->destinationAccount === $currentDestinationAccount) {
            return [$existing->destinationAccount, $existing->destinationTag];
        }

        return [
            $currentDestinationAccount,
            $this->destinationTagService->generateDestinationTag($currentDestinationAccount),
        ];
    }
}
