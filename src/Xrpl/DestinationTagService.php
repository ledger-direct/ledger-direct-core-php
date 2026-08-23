<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;

final class DestinationTagService
{
    private const RANGE_MIN = 10000;

    /**
     * XRPL's DestinationTag field is an unsigned 32-bit integer; this is
     * its true maximum (4294967295 = 2^32 - 1). Ground truth capped this at
     * 2140000000 to stay under a *signed* 32-bit MySQL `INT` column's max —
     * not an XRPL protocol limit. The core's schema uses `INT UNSIGNED` for
     * this column (see INVARIANTS.md), so the core uses the full range.
     */
    private const RANGE_MAX = 4294967295;

    private const RANGE_SIZE = self::RANGE_MAX - self::RANGE_MIN + 1;

    /**
     * Coprime to RANGE_SIZE (verified: gcd(1836311903, 4294957296) === 1) —
     * the necessary-and-sufficient condition for f(n) = (n * MULTIPLIER) mod
     * RANGE_SIZE to be a bijection over 0..RANGE_SIZE-1. Not secret: tags
     * aren't sensitive, this is a single shared constant across every
     * installation, same reasoning as the fixed oracle set / JSON-RPC URLs
     * elsewhere in the core. Also chosen so MULTIPLIER * (RANGE_SIZE - 1)
     * stays under PHP_INT_MAX on 64-bit — no BigInteger needed.
     */
    private const MULTIPLIER = 1836311903;

    /**
     * Arbitrary fixed constant so sequence 0 doesn't always map to exactly
     * RANGE_MIN; doesn't affect bijectivity for any fixed value.
     */
    private const OFFSET = 104729;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    /**
     * Deterministically derives a destination tag for $destinationAccount
     * from an atomic, strictly-increasing per-account sequence number
     * (TransactionRepositoryInterface::nextDestinationTagSequence()) run
     * through a bijective permutation over the tag range.
     *
     * Mathematically collision-free for a given account, not just
     * low-probability: distinct sequence numbers always produce distinct
     * tags, and the sequence itself never repeats before RANGE_SIZE calls
     * (the account's real exhaustion point — see below). No pre-generation,
     * no retry loop, O(1) regardless of how many tags the account already
     * has, and the result still looks random from the outside.
     */
    public function generateDestinationTag(string $destinationAccount): int
    {
        $sequence = $this->transactionRepository->nextDestinationTagSequence($destinationAccount);

        if ($sequence >= self::RANGE_SIZE || $sequence < 0) {
            throw new DestinationTagsExhaustedException(
                "Destination account {$destinationAccount} has issued all " . self::RANGE_SIZE
                . ' available destination tags.'
            );
        }

        return self::RANGE_MIN + (($sequence * self::MULTIPLIER + self::OFFSET) % self::RANGE_SIZE);
    }
}
