<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Account;

/**
 * Whether a merchant's receiving account can take a payment, before there is an order
 * (INVARIANTS.md, "Receiving account"). Two questions, one per screen:
 *
 * - the checkout asks offers(): leave out an asset nobody could pay, so no payment intent
 *   exists that cannot be settled;
 * - the configuration screen asks assess(): show the merchant what is missing, and keep the
 *   option visible ("it exists, the account only needs a trust line").
 *
 * Read-only. One implementation per chain family: Xrpl\AccountInspector (XRPL, Xahau),
 * Stellar\AccountInspector.
 */
interface ReceivingAccountCheck
{
    /**
     * Whether the checkout offers the asset for a payment to the account. Never throws: when
     * the node cannot be asked, an issued asset is left out (fail closed) and the native asset
     * stays. $amount is the amount the order would request, in the asset's units, when the
     * adapter knows it; without it the native asset is offered to an account that is not
     * activated yet, since the first payment at or above the base reserve activates it.
     */
    public function offers(string $account, string $asset, string $network, ?string $amount = null): bool;

    /**
     * Every finding about the account and the given assets. Reads the ledger afresh by
     * default — the merchant who just added a trust line expects to see it — and refreshes
     * what the checkout reads. Never throws: an unreachable node is a NOT_CHECKED finding.
     *
     * @param list<string> $assets asset codes as in ChainCatalog, e.g. ['XRP', 'RLUSD', 'USDC']
     */
    public function assess(string $account, string $network, array $assets, bool $fresh = true): Assessment;
}
