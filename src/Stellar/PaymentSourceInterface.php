<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

/**
 * Where the sync reads a receiving account's payments from. Horizon today;
 * Stellar RPC (CAP-67 `transfer` and `mint` events) as a second
 * implementation before Horizon goes away. The source maps its own wire
 * format onto StellarPayment and applies the operation filter; the sync
 * does not know which service it is talking to.
 *
 * @internal The boundary exists so the source can be swapped inside the
 *     core; it is not a platform port and may change without notice.
 */
interface PaymentSourceInterface
{
    /**
     * The next page of payments **to** $account after $cursor (null = from
     * the beginning), oldest first, already filtered to payment operations
     * whose destination is the account.
     *
     * @return array{payments: list<StellarPayment>, cursor: string|null, exhausted: bool}
     *     `cursor` is the position to continue from; `exhausted` says there is no further page
     */
    public function fetchPayments(string $account, string $network, ?string $cursor): array;

    /**
     * The newest ledger the source knows — what a stored cursor is checked
     * against to notice a testnet reset.
     */
    public function latestLedger(string $network): int;
}
