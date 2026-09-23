<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigInteger;
use InvalidArgumentException;

/**
 * One payment *operation* to a receiving account, as persisted by the
 * platform through {@see \Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface}.
 * Mirrors the `ledger_direct_stellar_payment` table (INVARIANTS.md,
 * "Stellar"), minus the storage-generated primary key.
 *
 * An operation, not a transaction: a Stellar transaction can carry several
 * payments, and the two operations of one transaction are two of these with
 * the same hash. The pair (hash, opIndex) is the identity — the operation
 * id repeats after a testnet reset, the hash does not.
 */
final readonly class StellarPayment
{
    public const ASSET_NATIVE = 'XLM';

    /**
     * @param string $network 'mainnet' | 'testnet'
     * @param string $paymentId Horizon's operation id (a TOID), as a decimal string; orders rows and is the cursor
     * @param string $hash transaction hash, hex
     * @param int $opIndex the operation's position within its transaction as encoded in the TOID (1-based)
     * @param string $type 'payment' | 'path_payment_strict_send' | 'path_payment_strict_receive'
     * @param string $account the sender (Horizon `from`)
     * @param string $destination the receiving account (Horizon `to`, always the G-address)
     * @param string|null $memoId the resolved identifier as a canonical decimal string — muxed id,
     *     MEMO_ID, or a MEMO_TEXT in canonical form; null when the payment carries none
     * @param string $assetCode plain code: 'XLM' for native, else e.g. 'USDC'
     * @param string|null $assetIssuer null for native
     * @param string $amount what arrived, as the decimal string Horizon sends (up to seven places)
     * @param int $createdAt unix timestamp of the ledger close
     * @param array<string, mixed> $raw the Horizon record, transaction join included
     */
    public function __construct(
        public string $network,
        public string $paymentId,
        public string $hash,
        public int $opIndex,
        public string $type,
        public string $account,
        public string $destination,
        public ?string $memoId,
        public string $assetCode,
        public ?string $assetIssuer,
        public string $amount,
        public int $createdAt,
        public array $raw,
    ) {
        if (preg_match('/^\d+$/', $paymentId) !== 1) {
            throw new InvalidArgumentException("paymentId must be a decimal string, got '{$paymentId}'.");
        }

        if ($opIndex < 1) {
            throw new InvalidArgumentException("opIndex is 1-based, got {$opIndex}.");
        }

        if ($memoId !== null && !self::isCanonicalIdentifier($memoId)) {
            throw new InvalidArgumentException("memoId must be in canonical decimal form, got '{$memoId}'.");
        }

        if (($assetCode === self::ASSET_NATIVE) !== ($assetIssuer === null)) {
            throw new InvalidArgumentException('A native amount has no issuer; an issued one must have one.');
        }
    }

    /** The dedup key: `"{hash}:{op_index}"`. */
    public function key(): string
    {
        return $this->hash . ':' . $this->opIndex;
    }

    /**
     * The ledger sequence the TOID encodes — its upper 32 bits. What the
     * testnet-reset check compares with Horizon's `history_latest_ledger`.
     */
    public function ledger(): int
    {
        return BigInteger::of($this->paymentId)->shiftedRight(32)->toInt();
    }

    /**
     * Whether a string is an identifier in canonical decimal form: no sign,
     * no spaces, no leading zeros, within uint64. The only form a MEMO_TEXT
     * is accepted in, and the form every identifier is compared in.
     */
    public static function isCanonicalIdentifier(string $value): bool
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1 || strlen($value) > 20) {
            return false;
        }

        return strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0;
    }
}
