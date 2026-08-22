<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Price;

use Hardcastle\LedgerDirect\Core\Price\Oracle\OracleInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared oracle-set/divergence-filter algorithm for the three price
 * providers. Not part of the public contract — extend within this package
 * only; not meant for platform code to subclass.
 *
 * @internal
 */
abstract class AbstractPriceProvider implements PriceProviderInterface
{
    public function __construct(
        protected readonly ClientInterface $httpClient,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function getCurrentExchangeRate(string $quoteCurrency, bool $round = false): float|false
    {
        $prices = [];

        foreach ($this->oracles() as $oracle) {
            try {
                $price = $oracle->getCurrentPriceForPair($this->cryptoCode(), $quoteCurrency);
                if ($price > 0.0) {
                    $prices[] = $price;
                }
            } catch (Throwable $exception) {
                $this->logger->warning('LedgerDirect: price oracle failed', [
                    'oracle' => $oracle::class,
                    'base' => $this->cryptoCode(),
                    'quote' => $quoteCurrency,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $filtered = self::filterDivergent($prices);
        if ($filtered === null) {
            return false;
        }

        $average = array_sum($filtered) / count($filtered);

        return $round ? round($average, $this->roundPlaces()) : $average;
    }

    abstract protected function cryptoCode(): string;

    abstract protected function roundPlaces(): int;

    /** @return OracleInterface[] the fixed oracle set for this asset */
    abstract protected function oracles(): array;

    /**
     * @param float[] $prices
     * @return float[]|null null when there's nothing left to average
     */
    private static function filterDivergent(array $prices): ?array
    {
        if (count($prices) === 0) {
            return null;
        }

        $average = array_sum($prices) / count($prices);
        $filtered = array_values(array_filter(
            $prices,
            static fn (float $price): bool => abs($average - $price) < $average * self::DEFAULT_ALLOWED_DIVERGENCE,
        ));

        return count($filtered) > 0 ? $filtered : null;
    }
}
