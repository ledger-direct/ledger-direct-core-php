<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Brick\Math\BigInteger;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;

/**
 * In-memory StellarPaymentRepositoryInterface — the core's reference
 * implementation of the port contract, and the double an adapter's
 * service tests use instead of a database.
 */
final class InMemoryStellarPaymentRepository implements StellarPaymentRepositoryInterface
{
    /** @var array<string, StellarPayment> keyed by StellarPayment::key(), in insertion order */
    private array $paymentsByKey = [];

    /** @var array<string, int> */
    private array $sequences = [];

    /** @var array<string, list<int>> */
    private array $scriptedSequences = [];

    private const MAX_RANDOM_START = 2147483647;

    public function nextMemoIdSequence(string $destinationAccount): int
    {
        if (!empty($this->scriptedSequences[$destinationAccount] ?? [])) {
            return array_shift($this->scriptedSequences[$destinationAccount]);
        }

        if (!isset($this->sequences[$destinationAccount])) {
            $this->sequences[$destinationAccount] = random_int(0, self::MAX_RANDOM_START);
        }

        return $this->sequences[$destinationAccount]++;
    }

    /**
     * Test control: the next calls for $destinationAccount return these
     * values, in order, before the real counter takes over.
     *
     * @param int[] $sequences
     */
    public function scriptSequences(string $destinationAccount, array $sequences): void
    {
        $this->scriptedSequences[$destinationAccount] = $sequences;
    }

    public function findExistingKeys(array $keys): array
    {
        return array_values(array_intersect($keys, array_keys($this->paymentsByKey)));
    }

    public function savePayments(array $payments): void
    {
        foreach ($payments as $payment) {
            $this->paymentsByKey[$payment->key()] = $payment;
        }
    }

    public function findPayments(string $destination, string $memoId): array
    {
        $matches = array_values(array_filter(
            $this->paymentsByKey,
            static fn (StellarPayment $p): bool => $p->destination === $destination && $p->memoId === $memoId,
        ));

        // payment_id DESC, tie-broken by "primary key" DESC = later insertion first.
        $matches = array_reverse($matches);
        usort(
            $matches,
            static fn (StellarPayment $a, StellarPayment $b): int
                => BigInteger::of($b->paymentId)->compareTo(BigInteger::of($a->paymentId)),
        );

        return $matches;
    }

    public function getLastPaymentId(string $destinationAccount, string $network): ?string
    {
        $max = null;

        foreach ($this->paymentsByKey as $payment) {
            if ($payment->destination !== $destinationAccount || $payment->network !== $network) {
                continue;
            }

            if ($max === null || BigInteger::of($payment->paymentId)->isGreaterThan(BigInteger::of($max))) {
                $max = $payment->paymentId;
            }
        }

        return $max;
    }

    public function truncate(): void
    {
        $this->paymentsByKey = [];
    }

    /**
     * Test control: everything stored, in insertion order.
     *
     * @return list<StellarPayment>
     */
    public function storedPayments(): array
    {
        return array_values($this->paymentsByKey);
    }
}
