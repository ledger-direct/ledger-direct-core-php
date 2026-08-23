<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;

final class DestinationTagService
{
    private const RANGE_MIN = 10000;

    private const RANGE_MAX = 2140000000;

    private const MAX_ATTEMPTS = 1000;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    /**
     * Picks a random, currently-unreserved destination tag and reserves it.
     *
     * Check-then-reserve, not atomic — see TransactionRepositoryInterface's
     * docblock for the residual (very low probability, given the range)
     * race between two concurrent calls.
     */
    public function generateDestinationTag(): int
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = random_int(self::RANGE_MIN, self::RANGE_MAX);

            if (!$this->transactionRepository->isDestinationTagReserved($candidate)) {
                $this->transactionRepository->reserveDestinationTag($candidate);

                return $candidate;
            }
        }

        throw new DestinationTagsExhaustedException(
            'Could not find a free destination tag after ' . self::MAX_ATTEMPTS . ' attempts.'
        );
    }
}
