<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Refund;

use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use InvalidArgumentException;

/**
 * Something on the ledger that concerns a refund and is not its clean execution: a payment that
 * went to the refund's destination with the wrong tag or amount, a second one, one the ledger
 * refused. Kept on the refund and shown to the merchant, so that no payment from the merchant's
 * account to a refund's destination goes unnoticed (INVARIANTS.md, "Refund").
 *
 * The code is stable; an adapter maps it to a text. One finding per refund and transaction.
 */
final readonly class RefundFinding
{
    /** A payment carrying the refund's reference went to another account than the refund's destination. */
    public const DESTINATION_MISMATCH = 'destination_mismatch';

    /** A payment reached the destination without the refund's tag, or with another one. */
    public const TAG_MISMATCH = 'tag_mismatch';

    /** A payment reached the destination with the refund's tag in another amount or asset. */
    public const AMOUNT_MISMATCH = 'amount_mismatch';

    /** A second payment that matches a refund already executed. */
    public const DUPLICATE = 'duplicate';

    /** A payment that matches a refund the merchant had cancelled. */
    public const AFTER_CANCEL = 'after_cancel';

    /** A matching payment from before the refund was created: it may have been paid by hand already. */
    public const PAID_BEFORE = 'paid_before';

    /** A successful transaction to the destination whose delivered amount cannot be read (not a Payment, e.g. Xahau's Remit). */
    public const UNREADABLE = 'unreadable';

    /** The ledger refused a payment to the destination; `result` says why, e.g. `tecDST_TAG_NEEDED`. */
    public const FAILED_ATTEMPT = 'failed_attempt';

    public const CODES = [
        self::DESTINATION_MISMATCH,
        self::TAG_MISMATCH,
        self::AMOUNT_MISMATCH,
        self::DUPLICATE,
        self::AFTER_CANCEL,
        self::PAID_BEFORE,
        self::UNREADABLE,
        self::FAILED_ATTEMPT,
    ];

    /**
     * @param string $hash the transaction the finding is about
     * @param string $account the account that sent it
     * @param string $destination the account it went to
     * @param int|null $destinationTag the tag it carried; null for none
     * @param LedgerAmount|null $delivered what it delivered; null when nothing or nothing readable
     * @param string $result the ledger's result code, `tesSUCCESS` or a `tec` code
     * @param int $recordedAt Unix time the core noticed it
     */
    public function __construct(
        public string $code,
        public string $hash,
        public int $ledgerIndex,
        public string $account,
        public string $destination,
        public ?int $destinationTag,
        public ?LedgerAmount $delivered,
        public string $result,
        public string $transactionType,
        public int $recordedAt,
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Unknown refund finding '{$code}'.");
        }

        if (preg_match('/^[0-9A-F]{64}\z/', $hash) !== 1) {
            throw new InvalidArgumentException('A refund finding needs the transaction hash, 64 hex digits in upper case.');
        }

        if ($destinationTag !== null && ($destinationTag < 0 || $destinationTag > RefundDestination::MAX_TAG)) {
            throw new InvalidArgumentException("A refund finding's destination tag is an unsigned 32-bit integer.");
        }

        if ($ledgerIndex < 0 || $recordedAt < 0) {
            throw new InvalidArgumentException("A refund finding's ledger index and time are not negative.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'hash' => $this->hash,
            'ledger_index' => $this->ledgerIndex,
            'account' => $this->account,
            'destination' => $this->destination,
            'destination_tag' => $this->destinationTag === null ? null : (string) $this->destinationTag,
            'delivered' => $this->delivered === null ? null : [
                'value' => $this->delivered->value,
                'currency' => $this->delivered->currency,
                'issuer' => $this->delivered->issuer,
            ],
            'result' => $this->result,
            'transaction_type' => $this->transactionType,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * Reads what toArray() wrote, strictly: a finding that does not read is a damaged record,
     * not a finding to skip.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['code', 'hash', 'ledger_index', 'account', 'destination', 'destination_tag', 'delivered', 'result', 'transaction_type', 'recorded_at'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("A refund finding is missing '{$field}'.");
            }
        }

        if (!is_string($data['code']) || !is_string($data['hash']) || !is_string($data['account'])
            || !is_string($data['destination']) || !is_string($data['result']) || !is_string($data['transaction_type'])
            || !is_int($data['ledger_index']) || !is_int($data['recorded_at'])
        ) {
            throw new InvalidArgumentException('A refund finding has a field of the wrong type.');
        }

        $tag = $data['destination_tag'];
        if ($tag !== null && (!is_string($tag) || !ctype_digit($tag) || strlen($tag) > 10)) {
            throw new InvalidArgumentException('A refund finding carries a destination tag that is not a decimal string.');
        }

        $delivered = $data['delivered'];
        if ($delivered !== null) {
            $currency = is_array($delivered) ? ($delivered['currency'] ?? null) : null;
            $issuer = is_array($delivered) ? ($delivered['issuer'] ?? null) : null;

            // The native asset has neither currency nor issuer, a token has both: half of the
            // pair is a damaged record, not a native amount.
            if (!is_array($delivered) || !is_string($delivered['value'] ?? null)
                || ($currency === null) !== ($issuer === null)
                || ($currency !== null && (!is_string($currency) || !is_string($issuer)))
            ) {
                throw new InvalidArgumentException('A refund finding carries a delivered amount that is not one.');
            }

            $delivered = $currency === null
                ? LedgerAmount::native($delivered['value'])
                : LedgerAmount::issued($currency, (string) $issuer, $delivered['value']);
        }

        return new self(
            code: $data['code'],
            hash: $data['hash'],
            ledgerIndex: $data['ledger_index'],
            account: $data['account'],
            destination: $data['destination'],
            destinationTag: $tag === null ? null : (int) $tag,
            delivered: $delivered,
            result: $data['result'],
            transactionType: $data['transaction_type'],
            recordedAt: $data['recorded_at'],
        );
    }
}
