<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use InvalidArgumentException;

/**
 * One transaction as a node reports it, read strictly: what it is, whether it is final, how it
 * ended and what it delivered. Reads both shapes a node answers with — an entry of `account_tx`
 * (`tx`, `meta`, `validated` side by side) and the result of `tx` (the fields flat, `meta` and
 * `validated` among them).
 *
 * Strict where the payment sync is lenient, on purpose. The sync skips an entry it cannot read:
 * a payment it misses stays unpaid and shows. Here a missed entry would be a payment that left
 * the merchant's account and was never noticed. So an entry without hash, without `validated`,
 * or validated without a result is an unusable answer ({@see XrplRpcException}), never
 * "nothing there". What a transaction of another kind does not have (a destination, a delivered
 * amount) is simply null.
 *
 * A failed transaction is in the list too: a `tec` result is validated, costs the fee and
 * delivers nothing. It appears in the sender's `account_tx` only, not in the destination's
 * (recorded on both testnets, 2026-11-20). succeeded() is the one question to ask before
 * believing any amount.
 *
 * @internal read by the core's own services; not part of the adapter contract
 */
final readonly class LedgerTransaction
{
    public const RESULT_SUCCESS = 'tesSUCCESS';

    public const TYPE_PAYMENT = 'Payment';

    /**
     * Payment flag tfPartialPayment: deliver as much of `Amount` as `SendMax` buys. `Amount` is
     * then only an upper bound (recorded: `Amount` 5, delivered 1), which is why this class does
     * not expose it.
     */
    public const TF_PARTIAL_PAYMENT = 0x00020000;

    /** Destination and source tags are unsigned 32-bit integers. */
    private const MAX_TAG = 4294967295;

    /**
     * @param int|null $ledgerIndex null while the transaction is not validated
     * @param string|null $result `meta.TransactionResult`; null while the transaction is not validated
     * @param int|null $destinationTag null when the transaction carries none, which is not tag 0
     * @param string|null $invoiceId the `InvoiceID` field, 64 hex digits in upper case
     * @param LedgerAmount|null $delivered `meta.delivered_amount` of a validated, successful
     *     Payment; null for everything else (another transaction type, failed, not validated, or
     *     an amount kind {@see LedgerAmount} does not model)
     * @param LedgerAmount|null $sendMax what the sender of a validated Payment was willing to
     *     spend, when stated; null for everything else
     * @param int|null $date seconds since the ledger's epoch (2000-01-01), as the ledger states it
     */
    private function __construct(
        public string $hash,
        public ?int $ledgerIndex,
        public bool $validated,
        public ?string $result,
        public string $type,
        public string $account,
        public ?string $destination,
        public ?int $destinationTag,
        public ?int $sourceTag,
        public ?string $invoiceId,
        public ?LedgerAmount $delivered,
        public ?LedgerAmount $sendMax,
        public int $flags,
        public ?int $date,
    ) {
    }

    /**
     * @param array<string, mixed> $entry one element of `transactions` in an `account_tx` result
     * @throws XrplRpcException when the entry is not usable
     */
    public static function fromAccountTx(array $entry): self
    {
        $tx = $entry['tx'] ?? null;
        if (!is_array($tx)) {
            throw self::unusable(null, "an account_tx entry without a 'tx' object");
        }

        return self::read($tx, $entry['meta'] ?? null, $entry['validated'] ?? null);
    }

    /**
     * @param array<string, mixed> $result the result of `tx`, as XrplClient::tx() returns it
     * @throws XrplRpcException when the result is not usable
     */
    public static function fromTx(array $result): self
    {
        return self::read($result, $result['meta'] ?? null, $result['validated'] ?? null);
    }

    /** Final and successful: the only state in which the transaction moved anything. */
    public function succeeded(): bool
    {
        return $this->validated && $this->result === self::RESULT_SUCCESS;
    }

    /** Final and not successful: the ledger refused it, e.g. `tecDST_TAG_NEEDED` or `tecPATH_DRY`. */
    public function failed(): bool
    {
        return $this->validated && $this->result !== self::RESULT_SUCCESS;
    }

    public function isPayment(): bool
    {
        return $this->type === self::TYPE_PAYMENT;
    }

    public function isPartialPayment(): bool
    {
        return $this->isPayment() && ($this->flags & self::TF_PARTIAL_PAYMENT) === self::TF_PARTIAL_PAYMENT;
    }

    /**
     * The sender spent one asset and the destination received another: the payment went through
     * the ledger's exchange. A `SendMax` alone does not say so — Xaman sets it on every token
     * payment, in the asset it delivers (scanned on the testnet, 2026-11-20) — only a `SendMax`
     * in another asset than the delivered one does.
     *
     * A `SendMax` whose issuer is the sender itself names no issuer: on a payment that means "any
     * issuer the sender can use" (xrpl.org, Payment, "Special issuer Values for SendMax and
     * Amount"). In the delivered currency it therefore proves nothing either way and is not
     * taken for another asset. Whether the sender can take the delivered asset back is for a
     * fresh look at its trust lines to say, not for this method.
     */
    public function isCrossCurrency(): bool
    {
        if ($this->sendMax === null || $this->delivered === null || $this->sendMax->sameAsset($this->delivered)) {
            return false;
        }

        $anyIssuer = $this->sendMax->issuer === $this->account;

        return !($anyIssuer && $this->sendMax->currency === $this->delivered->currency);
    }

    /**
     * @param array<string, mixed> $fields the transaction's own fields
     */
    private static function read(array $fields, mixed $meta, mixed $validated): self
    {
        $hash = $fields['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[0-9A-Fa-f]{64}\z/', $hash) !== 1) {
            throw self::unusable(null, 'no transaction hash');
        }
        $hash = strtoupper($hash);

        if (!is_bool($validated)) {
            throw self::unusable($hash, "it does not say whether it is validated");
        }

        $type = self::requiredString($fields, 'TransactionType', $hash);
        $account = self::requiredString($fields, 'Account', $hash);

        $result = null;
        $ledgerIndex = null;
        if ($validated) {
            $result = is_array($meta) ? ($meta['TransactionResult'] ?? null) : null;
            if (!is_string($result) || $result === '') {
                throw self::unusable($hash, 'it is validated but carries no meta.TransactionResult');
            }

            $ledgerIndex = $fields['ledger_index'] ?? null;
            if (!is_int($ledgerIndex) || $ledgerIndex < 1) {
                throw self::unusable($hash, 'it is validated but carries no ledger_index');
            }
        }

        $destination = $fields['Destination'] ?? null;
        if ($destination !== null && (!is_string($destination) || $destination === '')) {
            throw self::unusable($hash, 'its Destination is not an address');
        }

        $invoiceId = $fields['InvoiceID'] ?? null;
        if ($invoiceId !== null) {
            if (!is_string($invoiceId) || preg_match('/^[0-9A-Fa-f]{64}\z/', $invoiceId) !== 1) {
                throw self::unusable($hash, 'its InvoiceID is not 64 hex digits');
            }
            $invoiceId = strtoupper($invoiceId);
        }

        $flags = $fields['Flags'] ?? 0;
        if (!is_int($flags) || $flags < 0) {
            throw self::unusable($hash, 'its Flags are not an unsigned integer');
        }

        /*
         * Amounts are read from a final Payment and from nothing else. A CheckCash carries a
         * delivered amount too, and a CheckCreate a SendMax, but neither is a payment from
         * `Account` to `Destination`; and what a transaction says before it is validated is
         * not yet what happened.
         */
        $finalPayment = $validated && $type === self::TYPE_PAYMENT;

        $delivered = null;
        if ($finalPayment && $result === self::RESULT_SUCCESS && is_array($meta)) {
            $delivered = self::amount($meta['delivered_amount'] ?? $meta['DeliveredAmount'] ?? null);
        }

        $date = $fields['date'] ?? null;

        return new self(
            hash: $hash,
            ledgerIndex: $ledgerIndex,
            validated: $validated,
            result: $result,
            type: $type,
            account: $account,
            destination: $destination,
            destinationTag: self::tag($fields, 'DestinationTag', $hash),
            sourceTag: self::tag($fields, 'SourceTag', $hash),
            invoiceId: $invoiceId,
            delivered: $delivered,
            sendMax: $finalPayment ? self::amount($fields['SendMax'] ?? null) : null,
            flags: $flags,
            date: is_int($date) ? $date : null,
        );
    }

    /**
     * A tag, or null when the field is absent. Absent and 0 are two things: a payment without a
     * tag and a payment with tag 0 reach different customers at an exchange.
     *
     * @param array<string, mixed> $fields
     */
    private static function tag(array $fields, string $field, string $hash): ?int
    {
        if (!array_key_exists($field, $fields)) {
            return null;
        }

        $tag = $fields[$field];
        if (!is_int($tag) || $tag < 0 || $tag > self::MAX_TAG) {
            throw self::unusable($hash, "its {$field} is not an unsigned 32-bit integer");
        }

        return $tag;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function requiredString(array $fields, string $field, string $hash): string
    {
        $value = $fields[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw self::unusable($hash, "it carries no {$field}");
        }

        return $value;
    }

    /** An amount this class can read, or null: absent, `unavailable`, or of a kind LedgerAmount does not model. */
    private static function amount(mixed $wire): ?LedgerAmount
    {
        if ($wire === null) {
            return null;
        }

        try {
            return LedgerAmount::fromWire($wire);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function unusable(?string $hash, string $reason): XrplRpcException
    {
        return new XrplRpcException(
            'Unusable ledger transaction' . ($hash !== null ? " {$hash}" : '') . ": {$reason}."
        );
    }
}
