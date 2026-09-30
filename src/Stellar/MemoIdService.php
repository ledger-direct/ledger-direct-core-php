<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;

/**
 * Hands out the memo id a customer puts on their Stellar payment — the
 * counterpart of XRPL's DestinationTagService, over the same permutation
 * and the same range (INVARIANTS.md, "Stellar", Identifiers).
 *
 * Collision-free per counter: distinct sequence numbers always give
 * distinct memo ids, and the port guarantees the sequence never repeats
 * and starts at a random offset per installation.
 */
final class MemoIdService
{
    public function __construct(
        private readonly StellarPaymentRepositoryInterface $paymentRepository,
    ) {
    }

    public function generateMemoId(string $destinationAccount): int
    {
        $sequence = $this->paymentRepository->nextMemoIdSequence($destinationAccount);

        if (SequencePermutation::isExhausted($sequence)) {
            throw new MemoIdsExhaustedException(
                "Receiving account {$destinationAccount} has issued all " . SequencePermutation::RANGE_SIZE
                . ' available memo ids.'
            );
        }

        return SequencePermutation::map($sequence);
    }
}
