<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundChange;
use Hardcastle\LedgerDirect\Core\Refund\RefundFinding;

/**
 * Decides what one transaction from a paying account means for its refunds: it executes one,
 * or it is a finding on one or several, or it concerns none. A pure function of its arguments;
 * nothing is read or stored here (INVARIANTS.md, "Refund", Matching).
 *
 * A payment **executes** a refund only if it is validated and successful, went to the refund's
 * destination with the refund's tag — no tag and tag 0 are different — in the refund's asset
 * and in exactly the refund's amount, and sits in a ledger after the one the refund was created
 * in. Everything that comes close without being that is written down as a finding, never
 * assumed to be the refund.
 *
 * A payment that carries a refund's reference names that refund and no other: it executes it if
 * everything else fits, and is a finding on it if not. The reference never stands in for the
 * tag or the amount.
 *
 * @internal used by RefundReconciler
 */
final class RefundMatcher
{
    /** A transaction's `date` counts seconds from 2000-01-01; Unix time counts from 1970. */
    private const LEDGER_EPOCH_OFFSET = 946684800;

    /**
     * @param LedgerTransaction $transaction sent by the paying account, with a destination
     * @param list<Refund> $toDestination every refund from the paying account to the
     *     transaction's destination, oldest first
     * @param Refund|null $referenced the refund whose reference the transaction carries, if any
     * @param int $now Unix time, for what is recorded
     * @param int $closedSince a refund closed at or after this time is still looked after
     * @return list<RefundChange>
     */
    public function match(LedgerTransaction $transaction, array $toDestination, ?Refund $referenced, int $now, int $closedSince): array
    {
        if (!$transaction->validated || $transaction->ledgerIndex === null || $transaction->destination === null) {
            return [];
        }

        foreach ($referenced === null ? $toDestination : [...$toDestination, $referenced] as $refund) {
            if ($refund->executedHash === $transaction->hash) {
                // Already the execution of a refund; it is nothing else as well.
                return [];
            }
        }

        if ($transaction->failed()) {
            return self::findings(RefundFinding::FAILED_ATTEMPT, $transaction, self::attempted($transaction, $toDestination, $referenced), $now);
        }

        $delivered = $transaction->isPayment() ? $transaction->delivered : null;

        if ($delivered === null) {
            // Not a payment, or one whose delivered amount cannot be read (Xahau's Remit).
            $concerned = $referenced !== null ? [$referenced] : self::concerned($transaction, $toDestination, $closedSince);

            return self::findings(RefundFinding::UNREADABLE, $transaction, $concerned, $now);
        }

        if ($referenced !== null) {
            return self::againstReferenced($transaction, $delivered, $referenced, $now);
        }

        $exact = array_values(array_filter(
            $toDestination,
            static fn (Refund $refund): bool => $refund->destination->tag === $transaction->destinationTag
                && $refund->amount->equals($delivered),
        ));

        // The oldest open request the payment can be the execution of.
        foreach ($exact as $refund) {
            if ($refund->isAwaitingExecution() && $refund->createdLedger < $transaction->ledgerIndex) {
                return [self::execution($transaction, $delivered, $refund, Refund::MATCHED_BY_KEY, $now)];
            }
        }

        // The same payment from before the request existed: it may have been paid by hand already.
        foreach ($exact as $refund) {
            if ($refund->isAwaitingExecution()) {
                return self::findings(RefundFinding::PAID_BEFORE, $transaction, [$refund], $now);
            }
        }

        // Newest first: the request this payment most likely answers.
        $newestFirst = array_reverse($exact);

        foreach ($newestFirst as $refund) {
            if ($refund->isCancelled() && $refund->createdLedger < $transaction->ledgerIndex) {
                return self::findings(RefundFinding::AFTER_CANCEL, $transaction, [$refund], $now);
            }
        }

        foreach ($newestFirst as $refund) {
            if ($refund->isExecuted() && $refund->createdLedger < $transaction->ledgerIndex) {
                return self::findings(RefundFinding::DUPLICATE, $transaction, [$refund], $now);
            }
        }

        // Nothing fits exactly, but money went to a destination that is being looked after.
        $changes = [];
        foreach (self::concerned($transaction, $toDestination, $closedSince) as $refund) {
            $code = $refund->destination->tag !== $transaction->destinationTag
                ? RefundFinding::TAG_MISMATCH
                : RefundFinding::AMOUNT_MISMATCH;
            array_push($changes, ...self::findings($code, $transaction, [$refund], $now));
        }

        return $changes;
    }

    /**
     * @return list<RefundChange>
     */
    private static function againstReferenced(LedgerTransaction $transaction, LedgerAmount $delivered, Refund $refund, int $now): array
    {
        if ($refund->destination->account !== $transaction->destination) {
            return self::findings(RefundFinding::DESTINATION_MISMATCH, $transaction, [$refund], $now);
        }

        if ($refund->destination->tag !== $transaction->destinationTag) {
            return self::findings(RefundFinding::TAG_MISMATCH, $transaction, [$refund], $now);
        }

        if (!$refund->amount->equals($delivered)) {
            return self::findings(RefundFinding::AMOUNT_MISMATCH, $transaction, [$refund], $now);
        }

        if ($refund->isAwaitingExecution()) {
            return $refund->createdLedger < $transaction->ledgerIndex
                ? [self::execution($transaction, $delivered, $refund, Refund::MATCHED_BY_REFERENCE, $now)]
                : self::findings(RefundFinding::PAID_BEFORE, $transaction, [$refund], $now);
        }

        return self::findings(
            $refund->isCancelled() ? RefundFinding::AFTER_CANCEL : RefundFinding::DUPLICATE,
            $transaction,
            [$refund],
            $now,
        );
    }

    /**
     * The refunds a refused payment was an attempt at: the one it names, or every open one to
     * its destination. A refused payment delivered nothing, so there is no amount to go by.
     *
     * @param list<Refund> $toDestination
     * @return list<Refund>
     */
    private static function attempted(LedgerTransaction $transaction, array $toDestination, ?Refund $referenced): array
    {
        $candidates = $referenced !== null ? [$referenced] : $toDestination;

        return array_values(array_filter(
            $candidates,
            static fn (Refund $refund): bool => $refund->isAwaitingExecution() && $refund->createdLedger < $transaction->ledgerIndex,
        ));
    }

    /**
     * The refunds to the transaction's destination that a payment "close to" a refund is a
     * finding on: the open ones created before it; if there is none, those closed recently
     * enough to be looked after still.
     *
     * @param list<Refund> $toDestination
     * @return list<Refund>
     */
    private static function concerned(LedgerTransaction $transaction, array $toDestination, int $closedSince): array
    {
        $before = array_values(array_filter(
            $toDestination,
            static fn (Refund $refund): bool => $refund->createdLedger < $transaction->ledgerIndex,
        ));

        $open = array_values(array_filter($before, static fn (Refund $refund): bool => $refund->isAwaitingExecution()));
        if ($open !== []) {
            return $open;
        }

        return array_values(array_filter(
            $before,
            static fn (Refund $refund): bool => $refund->closedAt !== null && $refund->closedAt >= $closedSince,
        ));
    }

    private static function execution(LedgerTransaction $transaction, LedgerAmount $delivered, Refund $refund, string $matchedBy, int $now): RefundChange
    {
        return new RefundChange($refund, $refund->executed(
            hash: $transaction->hash,
            ledgerIndex: (int) $transaction->ledgerIndex,
            executedAt: $transaction->date === null ? $now : $transaction->date + self::LEDGER_EPOCH_OFFSET,
            account: $transaction->account,
            delivered: $delivered,
            matchedBy: $matchedBy,
            closedAt: $now,
        ));
    }

    /**
     * @param list<Refund> $refunds
     * @return list<RefundChange> one per refund that does not have a finding for this transaction yet
     */
    private static function findings(string $code, LedgerTransaction $transaction, array $refunds, int $now): array
    {
        $finding = new RefundFinding(
            code: $code,
            hash: $transaction->hash,
            ledgerIndex: (int) $transaction->ledgerIndex,
            account: $transaction->account,
            destination: (string) $transaction->destination,
            destinationTag: $transaction->destinationTag,
            delivered: $transaction->delivered,
            result: (string) $transaction->result,
            transactionType: $transaction->type,
            recordedAt: $now,
        );

        $changes = [];
        foreach ($refunds as $refund) {
            $after = $refund->withFinding($finding);
            if ($after !== $refund) {
                $changes[] = new RefundChange($refund, $after, $finding);
            }
        }

        return $changes;
    }
}
