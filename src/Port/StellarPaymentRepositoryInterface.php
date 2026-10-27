<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Port;

use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;

/**
 * Provided by the platform. Persistence for `ledger_direct_stellar_payment`
 * and `ledger_direct_stellar_memo` (see INVARIANTS.md, "Stellar"). Storage
 * primitives only — memo-id generation and sync/dedup orchestration are
 * core-side, not the platform's concern.
 *
 * The sibling of XrplTransactionRepositoryInterface, deliberately not a
 * generalisation of it: Horizon paginates by operation id, rippled by ledger
 * index and marker, and one row here is a payment *operation*, not a
 * transaction. See `Stellar\Schema` for the columns and indexes.
 */
interface StellarPaymentRepositoryInterface
{
    /**
     * Returns a number that is unique and strictly increasing per
     * $destinationAccount; MemoIdService turns it into the memo id through
     * the same permutation DestinationTagService uses.
     *
     * The first value for an account MUST be a fresh `random_int(0, 2 ** 31 - 1)`,
     * drawn once when the counter row is created; every later call returns
     * the previous value plus one. It MUST NOT start at 0, MUST be atomic under
     * concurrent calls for the same account, and the counter table MUST
     * survive plugin uninstall. The reasons are the XRPL port's, verbatim:
     * {@see XrplTransactionRepositoryInterface::nextDestinationTagSequence()}.
     */
    public function nextMemoIdSequence(string $destinationAccount): int;

    /**
     * Which of these payments are already stored. The key is
     * {@see StellarPayment::key()} — `"{hash}:{op_index}"` — because the
     * operation id is not safe as a unique key: it repeats after a testnet
     * reset (INVARIANTS.md, "Stellar", Tables).
     *
     * @param list<string> $keys
     * @return list<string> the subset of $keys already stored
     */
    public function findExistingKeys(array $keys): array;

    /**
     * @param list<StellarPayment> $payments
     */
    public function savePayments(array $payments): void;

    /**
     * Every stored payment operation to this receiving account with this
     * resolved identifier, **newest first**: `ORDER BY payment_id DESC`,
     * tie-broken by the storage primary key descending so the order is total.
     *
     * $memoId is the canonical decimal string of the identifier (the
     * intent's `destination_tag`, cast) — identifiers are strings end to end
     * because a foreign one can exceed PHP's signed 64-bit range.
     *
     * Plural on purpose: a memo can carry several operations — two partial
     * payments, a payment in the wrong asset, two operations of one
     * transaction — and which of them fulfil an intent is a core decision
     * (Stellar\SyncService::findFulfillmentFor()), not a storage one.
     *
     * @return list<StellarPayment> newest first; empty when nothing matches
     */
    public function findPayments(string $destination, string $memoId): array;

    /**
     * The highest `payment_id` stored for this receiving account **on this
     * network** — `WHERE destination = ? AND network = ?` — as a decimal
     * string, or null when nothing has been synced for that pair yet. The
     * sync sends it back to Horizon as the cursor.
     *
     * Both parameters are load-bearing, exactly as for the XRPL cursor: an
     * operation id only means anything within one network, and a merchant
     * who switches receiving accounts must not resume at the old account's
     * position. A global `MAX(payment_id)` is not a valid implementation.
     */
    public function getLastPaymentId(string $destinationAccount, string $network): ?string;

    public function truncate(): void;
}
