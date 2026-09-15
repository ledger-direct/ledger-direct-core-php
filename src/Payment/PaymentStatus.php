<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

/**
 * The answer to "is this order paid?" — one of five states, derived from a PaymentIntent, a
 * SettlementPolicy and the clock. See INVARIANTS.md, "Payment status".
 *
 * Derived, never stored: there is no new record and no migration. Every platform's status
 * endpoint serializes this (`toArray()`) and appends what only the platform knows — the
 * `redirect` URL — so four adapters answer the same question in the same shape. The core knows
 * no HTTP and formats no amounts; what the customer reads is the adapter's job.
 */
final readonly class PaymentStatus
{
    public const SCHEMA_VERSION = 1;

    /** Nothing has arrived and the quote is still valid. Carries `seconds_left`. */
    public const WAITING = 'waiting';

    /** Something arrived in the quoted asset, but not enough. Carries `amount_paid` and `shortfall`. */
    public const PARTIAL = 'partial';

    /** Something arrived, but in another currency or from another issuer. Carries `amount_paid` and `shortfall`. */
    public const WRONG_ASSET = 'wrong_asset';

    /** Paid. The only terminal state. */
    public const SETTLED = 'settled';

    /** Nothing arrived and the quote's expiry has passed. */
    public const EXPIRED = 'expired';

    /**
     * How long a status endpoint should answer from the stored PaymentIntent before it syncs
     * with the ledger again for the *same* order. Every call that syncs costs a node request
     * (~1 s measured against ~0.09 s without), and an unauthenticated endpoint polled every few
     * seconds by every waiting customer is otherwise a free amplifier against the merchant's own
     * XRPL node. The throttling itself, and the timestamp of the last sync, live in the adapter —
     * the core only names the number so four platforms don't each pick their own.
     */
    public const MIN_SYNC_INTERVAL_SECONDS = 5;

    /**
     * @param float|array{currency: string, value: string, issuer: string} $amountRequested
     * @param float|array{currency: string, value: string, issuer: string}|null $amountPaid
     * @param float|array{currency: string, value: string, issuer: string}|null $shortfall
     */
    private function __construct(
        public string $state,
        public string $baseAsset,
        public float|array $amountRequested,
        public float|array|null $amountPaid,
        public float|array|null $shortfall,
        public ?int $secondsLeft,
    ) {
    }

    /**
     * Derives the state in this order — the order is part of the contract:
     *
     *  1. the policy says settled            → settled
     *  2. nothing paid and expiry passed     → expired
     *  3. nothing paid                       → waiting
     *  4. paid, but currency/issuer differ   → wrong_asset
     *  5. otherwise                          → partial
     *
     * A partial payment on an expired quote is therefore `partial`, not `expired`: the customer
     * sent real money, and the page has to say so before it talks about validity periods.
     * `expired` is reserved for "nothing there, and the rate is stale".
     *
     * @param int|null $now unix timestamp; defaults to the current time. Injectable for tests.
     */
    public static function fromIntent(PaymentIntent $intent, SettlementPolicy $policy, ?int $now = null): self
    {
        $now ??= time();

        if ($policy->isSettled($intent)) {
            return new self(self::SETTLED, $intent->baseAsset, $intent->amountRequested, $intent->amountPaid, null, null);
        }

        if ($intent->amountPaid === null) {
            if ($intent->expiry !== null && $now >= $intent->expiry) {
                return new self(self::EXPIRED, $intent->baseAsset, $intent->amountRequested, null, null, null);
            }

            $secondsLeft = $intent->expiry === null ? null : $intent->expiry - $now;

            return new self(self::WAITING, $intent->baseAsset, $intent->amountRequested, null, null, $secondsLeft);
        }

        $state = $policy->isWrongAsset($intent) ? self::WRONG_ASSET : self::PARTIAL;
        $shortfall = self::shapeLikeRequested($intent, (string) $policy->shortfall($intent));

        return new self($state, $intent->baseAsset, $intent->amountRequested, $intent->amountPaid, $shortfall, null);
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * Whether a frontend may stop polling. Only `settled` is terminal: `partial` and
     * `wrong_asset` must keep polling so a top-up payment is noticed, and `expired` may still
     * turn into `partial` if a payment lands late.
     */
    public function isTerminal(): bool
    {
        return $this->state === self::SETTLED;
    }

    /**
     * The status payload, literally. `schema_version` comes first, as in PaymentIntent.
     * Every key is always present; the ones a state does not use are null.
     *
     * @return array{
     *     schema_version: int,
     *     state: string,
     *     base_asset: string,
     *     amount_requested: float|array,
     *     amount_paid: float|array|null,
     *     shortfall: float|array|null,
     *     seconds_left: int|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $this->state,
            'base_asset' => $this->baseAsset,
            'amount_requested' => $this->amountRequested,
            'amount_paid' => $this->amountPaid,
            'shortfall' => $this->shortfall,
            'seconds_left' => $this->secondsLeft,
        ];
    }

    /**
     * SettlementPolicy reports the shortfall as a plain decimal string; the payload carries it in
     * the shape of `amount_requested` so an adapter formats it with the same code path as the
     * request: a float for a native asset, an IssuedCurrencyAmount — with the *quoted* currency
     * and issuer, never the delivered one — for everything else.
     *
     * @return float|array{currency: string, value: string, issuer: string}
     */
    private static function shapeLikeRequested(PaymentIntent $intent, string $shortfall): float|array
    {
        if (is_array($intent->amountRequested)) {
            return [
                'currency' => $intent->amountRequested['currency'],
                'value' => $shortfall,
                'issuer' => $intent->amountRequested['issuer'],
            ];
        }

        return (float) $shortfall;
    }
}
