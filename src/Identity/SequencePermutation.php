<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Identity;

/**
 * The bijection that turns a per-account counter into a payment identifier
 * — XRPL's destination tag and Stellar's memo id alike. One algorithm, one
 * range, one set of constants, so a subtle mistake in the multiplier (the
 * kind that once let two shops issue each other's tags) can only be made
 * in one place.
 *
 * f(n) = RANGE_MIN + ((n * MULTIPLIER + OFFSET) mod RANGE_SIZE)
 *
 * The multiplier is coprime to RANGE_SIZE, which is the necessary and
 * sufficient condition for f to be a bijection over 0..RANGE_SIZE-1;
 * distinct sequence numbers therefore always give distinct identifiers.
 * The output is the same for both chains on purpose — a memo id range
 * wider than 2^32 would cost PHP's signed 64-bit int and every adapter's
 * column type for nothing (INVARIANTS.md, "Stellar", Identifiers).
 *
 * @internal Use DestinationTagService or MemoIdService; the constants and
 *     the mapping are fixed and never vary per installation.
 */
final class SequencePermutation
{
    public const RANGE_MIN = 10000;

    /**
     * 2^32 - 1: the true maximum of XRPL's DestinationTag field, and a
     * comfortable subset of Stellar's uint64 MEMO_ID. Ground truth capped
     * this at 2140000000 to fit a *signed* MySQL INT — a schema choice, not
     * a protocol limit, and the core's schema uses INT UNSIGNED.
     */
    public const RANGE_MAX = 4294967295;

    public const RANGE_SIZE = self::RANGE_MAX - self::RANGE_MIN + 1;

    /**
     * Coprime to RANGE_SIZE (verified: gcd(1836311903, 4294957296) === 1).
     * Not secret: identifiers aren't sensitive, and this is a single shared
     * constant across every installation. Chosen so MULTIPLIER * (RANGE_SIZE - 1)
     * stays under PHP_INT_MAX on 64-bit — no BigInteger needed.
     */
    public const MULTIPLIER = 1836311903;

    /** Arbitrary fixed constant so sequence 0 doesn't map to exactly RANGE_MIN. */
    public const OFFSET = 104729;

    /**
     * Whether the counter has run past the range — the account's real
     * exhaustion point, after RANGE_SIZE identifiers.
     */
    public static function isExhausted(int $sequence): bool
    {
        return $sequence >= self::RANGE_SIZE || $sequence < 0;
    }

    /**
     * The identifier for a sequence number. Callers check isExhausted()
     * first and throw their chain's own exception.
     */
    public static function map(int $sequence): int
    {
        return self::RANGE_MIN + (($sequence * self::MULTIPLIER + self::OFFSET) % self::RANGE_SIZE);
    }
}
