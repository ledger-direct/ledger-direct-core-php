<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Port;

use Hardcastle\LedgerDirect\Core\Refund\Refund;

/**
 * Provided by the platform. Persistence for a chain's refund table, `ledger_direct_xrpl_refund`
 * or `ledger_direct_xahau_refund` (see Xrpl\RefundSchema and INVARIANTS.md, "Refund"). One
 * instance per chain, like the transaction repository; every method is scoped by network where
 * a network is part of the question. The row does not say which chain it is from — the table
 * does — so insert() and update() refuse a refund of another chain with an
 * InvalidArgumentException rather than store it where it would read back as this chain's.
 *
 * Storage primitives only. The implementation writes Refund::toRow() and reads with
 * Refund::fromRow(); which refund may exist, what executes it and what a payment on the ledger
 * means is decided in the core. Four rules, though, only storage can hold, because two
 * requests can arrive at the same moment — they are **unique indexes**, never a query followed
 * by a write:
 *
 * - `(network, source_hash, serial)`: the refunds of one incoming payment are numbered. Two
 *   requests that counted the same refunds get the same number, and the second one is refused.
 *   That is what keeps two simultaneous refunds from both fitting under the payment's amount.
 * - `open_key`: no second open refund to the same destination, tag, asset and amount. The
 *   column is null once the refund is executed or cancelled, and null never collides.
 * - `executed_hash`: one payment executes one refund.
 * - `(network, reference)`: a payment that carries a reference names exactly one refund.
 *
 * **The table is a record, not a cache.** It holds what is nowhere on the ledger: which order a
 * payment was for, its purpose, where the destination came from, who confirmed it, whether it
 * is booked. Nothing empties it: no sync, no testnet reset, no clean-up job, and it survives
 * uninstall like the destination-tag counter. There is deliberately no truncate() here.
 */
interface RefundRepositoryInterface
{
    /**
     * Stores a new refund.
     *
     * @return bool false when a unique index refused it (the id, the numbering, the open key,
     *     the execution hash or the reference); nothing is stored then. Any other failure is
     *     an exception.
     */
    public function insert(Refund $refund): bool;

    /**
     * Replaces the stored refund with this one, if the stored version is still
     * $expectedVersion: `UPDATE … WHERE id = ? AND version = ?`, every column from toRow().
     * $refund carries the new version.
     *
     * @return bool false when the stored version has moved on, the refund does not exist, or a
     *     unique index refused the change; nothing is stored then
     */
    public function update(Refund $refund, int $expectedVersion): bool;

    public function find(string $id): ?Refund;

    /**
     * The refund whose payment request carries this reference, on this network. A payment on
     * the ledger names its refund with it (the `InvoiceID` field).
     */
    public function findByReference(string $network, string $reference): ?Refund;

    /**
     * Every refund of one incoming payment, whatever its status, by `serial` ascending.
     *
     * @return list<Refund>
     */
    public function findBySource(string $network, string $sourceHash): array;

    /**
     * Every refund the platform filed under this reference, oldest first: `created_at`
     * ascending, then `id` ascending so the order is total.
     *
     * @return list<Refund>
     */
    public function findByPlatformReference(string $platformReference): array;

    /**
     * Every refund from this paying account to this destination on this network, whatever its
     * status or tag, oldest first as in findByPlatformReference(). What a payment found on the
     * ledger is compared with.
     *
     * @return list<Refund>
     */
    public function findByDestination(string $network, string $payingAccount, string $destination): array;

    /**
     * The refunds of a paying account that are still looked after: those awaiting execution,
     * and those executed or cancelled at or after $closedSince (`closed_at >= ?`). Oldest first.
     *
     * @return list<Refund>
     */
    public function findWatched(string $network, string $payingAccount, int $closedSince): array;

    /**
     * The paying accounts that have a refund findWatched() would return, each once.
     *
     * @return list<string>
     */
    public function watchedAccounts(string $network, int $closedSince): array;
}
