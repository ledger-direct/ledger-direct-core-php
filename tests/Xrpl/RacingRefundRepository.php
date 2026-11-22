<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Closure;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\Refund;

/**
 * A repository in which somebody else gets there first: just before the first insert, another
 * request runs to completion. What two simultaneous requests look like to the one that loses.
 */
final class RacingRefundRepository implements RefundRepositoryInterface
{
    private bool $raced = false;

    /**
     * @param Closure(): void $rival runs once, before the first insert
     */
    public function __construct(
        private readonly RefundRepositoryInterface $inner,
        private readonly Closure $rival,
    ) {
    }

    public function insert(Refund $refund): bool
    {
        if (!$this->raced) {
            $this->raced = true;
            ($this->rival)();
        }

        return $this->inner->insert($refund);
    }

    public function update(Refund $refund, int $expectedVersion): bool
    {
        return $this->inner->update($refund, $expectedVersion);
    }

    public function find(string $id): ?Refund
    {
        return $this->inner->find($id);
    }

    public function findByReference(string $network, string $reference): ?Refund
    {
        return $this->inner->findByReference($network, $reference);
    }

    public function findBySource(string $network, string $sourceHash): array
    {
        return $this->inner->findBySource($network, $sourceHash);
    }

    public function findByPlatformReference(string $platformReference): array
    {
        return $this->inner->findByPlatformReference($platformReference);
    }

    public function findByDestination(string $network, string $payingAccount, string $destination): array
    {
        return $this->inner->findByDestination($network, $payingAccount, $destination);
    }

    public function findWatched(string $network, string $payingAccount, int $closedSince): array
    {
        return $this->inner->findWatched($network, $payingAccount, $closedSince);
    }

    public function watchedAccounts(string $network, int $closedSince): array
    {
        return $this->inner->watchedAccounts($network, $closedSince);
    }
}
