<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;

final class DestinationTagService
{
    public function __construct(
        private readonly XrplTransactionRepositoryInterface $transactionRepository,
    ) {
    }

    /**
     * Deterministically derives a destination tag for $destinationAccount
     * from an atomic, strictly-increasing per-account sequence number
     * (XrplTransactionRepositoryInterface::nextDestinationTagSequence()) run
     * through the bijective permutation in Identity\SequencePermutation —
     * shared with Stellar's MemoIdService, so the constants and the mapping
     * live in exactly one place.
     *
     * Mathematically collision-free for a given account, not just
     * low-probability: distinct sequence numbers always produce distinct
     * tags, and the sequence itself never repeats before RANGE_SIZE calls
     * (the account's real exhaustion point). No pre-generation, no retry
     * loop, O(1) regardless of how many tags the account already has.
     *
     * That guarantee holds **per counter**, i.e. per installation. Two
     * installations sharing one receiving account are two counters, so the
     * port requires each to start at its own random offset — otherwise both
     * walk the same sequence and issue the same tags. Nothing here needs to
     * change for that: the permutation is a bijection over the whole range,
     * so it does not care where the counter starts.
     */
    public function generateDestinationTag(string $destinationAccount): int
    {
        $sequence = $this->transactionRepository->nextDestinationTagSequence($destinationAccount);

        if (SequencePermutation::isExhausted($sequence)) {
            throw new DestinationTagsExhaustedException(
                "Destination account {$destinationAccount} has issued all " . SequencePermutation::RANGE_SIZE
                . ' available destination tags.'
            );
        }

        return SequencePermutation::map($sequence);
    }
}
