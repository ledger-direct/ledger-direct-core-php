<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use UnexpectedValueException;

/**
 * A synced XRPL transaction, as persisted by the platform through
 * {@see \Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface}.
 * Mirrors the `ledger_direct_xrpl_tx` table (see INVARIANTS.md), minus the
 * storage-generated primary key.
 */
final readonly class XrplTransaction
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $tx
     */
    public function __construct(
        public string $ledgerIndex,
        public string $hash,
        public string $ctid,
        public string $account,
        public string $destination,
        public ?int $destinationTag,
        public int $date,
        public array $meta,
        public array $tx,
    ) {
    }

    /**
     * What this transaction actually delivered, already in the shape
     * {@see \Hardcastle\LedgerDirect\Core\Payment\PaymentIntent::withFulfillment()}
     * expects: an XRP float, or an IssuedCurrencyAmount array.
     *
     * Reads the ledger's `delivered_amount` — the only field safe to credit
     * an order with, because on a partial payment the transaction's own
     * `Amount` is what the sender *asked* to deliver, not what arrived.
     *
     * Returns null when the transaction delivered nothing measurable: it
     * isn't a Payment at all (an EscrowCreate or CheckCreate also carries a
     * Destination and can land in the same table).
     *
     * @return float|array{currency: string, value: string, issuer: string}|null
     */
    public function getDeliveredAmount(): float|array|null
    {
        $delivered = $this->meta['delivered_amount'] ?? $this->meta['DeliveredAmount'] ?? null;

        if ($delivered === null) {
            return null;
        }

        /*
         * The ledger's own marker for a pre-2014 partial payment whose
         * delivered amount it cannot reconstruct. Distinct from null: money
         * did arrive, the amount is just unknowable — so refuse rather than
         * report "nothing delivered" and silently leave an order unpaid.
         */
        if ($delivered === 'unavailable') {
            throw new UnexpectedValueException(
                "Transaction {$this->hash} reports its delivered amount as 'unavailable'."
            );
        }

        if (!is_string($delivered) && !is_int($delivered) && !is_array($delivered)) {
            throw new UnexpectedValueException(sprintf(
                'Transaction %s has a delivered amount of unexpected type %s.',
                $this->hash,
                get_debug_type($delivered),
            ));
        }

        return XrplAmount::decode($delivered);
    }
}
