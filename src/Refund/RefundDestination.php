<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use InvalidArgumentException;

/**
 * Where a refund goes, and how the core came to that address.
 *
 * The rule this object holds: **a destination without a tag carries an attestation.** An
 * account at an exchange is shared by its customers and told apart by the tag; a refund sent
 * there without one arrives and belongs to nobody. Whether an address is such an account cannot
 * be read from the ledger with certainty, so "no tag" is never the default: somebody has to have
 * said that the address is the customer's own wallet (INVARIANTS.md, "Refund").
 */
final readonly class RefundDestination
{
    /** The account the payment being refunded came from. */
    public const ORIGIN_SENDER = 'sender';

    /** An address the customer named and the merchant entered. */
    public const ORIGIN_STATED = 'stated';

    public const ORIGINS = [self::ORIGIN_SENDER, self::ORIGIN_STATED];

    /** The merchant confirmed that the customer called this address their own wallet. */
    public const ATTESTATION_OWN_WALLET = 'own_wallet';

    public const ATTESTATIONS = [self::ATTESTATION_OWN_WALLET];

    /** A destination tag is an unsigned 32-bit integer. */
    public const MAX_TAG = 4294967295;

    /**
     * @param int|null $tag null for no tag, which is not tag 0
     * @param string|null $attestation why a destination without a tag was accepted; required then
     */
    public function __construct(
        public string $account,
        public ?int $tag,
        public string $origin,
        public ?string $attestation = null,
    ) {
        if ($account === '') {
            throw new InvalidArgumentException('A refund destination needs an account.');
        }

        if ($tag !== null && ($tag < 0 || $tag > self::MAX_TAG)) {
            throw new InvalidArgumentException("Destination tag {$tag} is not an unsigned 32-bit integer.");
        }

        if (!in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException("Unknown destination origin '{$origin}'.");
        }

        if ($attestation !== null && !in_array($attestation, self::ATTESTATIONS, true)) {
            throw new InvalidArgumentException("Unknown attestation '{$attestation}'.");
        }

        if ($tag === null && $attestation === null) {
            throw new InvalidArgumentException('A refund destination without a tag needs an attestation.');
        }
    }

    /**
     * @return array{account: string, tag: string|null, origin: string, attestation: string|null}
     */
    public function toArray(): array
    {
        return [
            'account' => $this->account,
            // A decimal string: the full unsigned range survives every JSON reader.
            'tag' => $this->tag === null ? null : (string) $this->tag,
            'origin' => $this->origin,
            'attestation' => $this->attestation,
        ];
    }
}
