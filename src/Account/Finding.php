<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Account;

/**
 * One thing about a receiving account a merchant should know before a customer tries to pay
 * (INVARIANTS.md, "Receiving account"). The code is stable — an adapter maps it to a snippet;
 * `asset` names the asset it concerns, null when it concerns the account as a whole.
 *
 * `blocksOffer` says whether the checkout leaves the asset out because of it. A finding that
 * does not block (the account needs activating, deposit authorization) is a warning only.
 */
final readonly class Finding
{
    /** The account does not exist on the ledger: on XRPL and Xahau it is not activated yet. */
    public const ACCOUNT_MISSING = 'account_missing';

    /** XRPL/Xahau: only pre-authorized senders can pay the account (`DepositAuth`). */
    public const DEPOSIT_AUTH = 'deposit_auth';

    /** No trust line for the issued asset: a payment fails (tecPATH_DRY, op_no_trust). */
    public const TRUSTLINE_MISSING = 'trustline_missing';

    /** The issuer requires authorized trust lines and has not authorized this one. */
    public const TRUSTLINE_NOT_AUTHORIZED = 'trustline_not_authorized';

    /** The trust line is frozen. */
    public const TRUSTLINE_FROZEN = 'trustline_frozen';

    /** The trust line's limit is reached: nothing more can arrive. */
    public const TRUSTLINE_FULL = 'trustline_full';

    /** The issuer froze every trust line in its tokens (global freeze). */
    public const ISSUER_FROZEN = 'issuer_frozen';

    /** The node could not be asked; nothing is known. */
    public const NOT_CHECKED = 'not_checked';

    /**
     * @param array<string, string> $context what the snippet may show, e.g. `reserve`, `issuer`, `error`
     */
    public function __construct(
        public string $code,
        public ?string $asset,
        public bool $blocksOffer,
        public array $context = [],
    ) {
    }
}
