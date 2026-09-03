<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * The versioned payment record (schema v1) — the cross-plugin contract
 * described in INVARIANTS.md. Named chain-agnostically because further
 * chains are expected to join `chain` later.
 *
 * `schema_version` is not the DB migration / plugin version, which
 * describes the database structure or the release and lives outside
 * this record.
 */
final readonly class PaymentIntent
{
    public const SCHEMA_VERSION = 1;

    /**
     * The chain's native asset is float-shaped; every other asset on that
     * chain is an IssuedCurrencyAmount — see assertAmountShape(). Not a
     * closed allowlist of accepted assets: a merchant-issued token (e.g. a
     * future Loyalty point) the core has never heard of is still accepted,
     * as an issued currency, without needing a core change. An unrecognized
     * chain defaults to "everything is an issued currency" (the safer,
     * more structured shape) rather than silently accepting a bare float.
     */
    private const NATIVE_ASSET_BY_CHAIN = [
        'XRPL' => 'XRP',
    ];

    private const REQUIRED_FIELDS = [
        'type', 'chain', 'network', 'base_asset', 'quote_currency', 'pairing',
        'exchange_rate', 'amount_requested', 'destination_account', 'destination_tag',
    ];

    private function __construct(
        public int $schemaVersion,
        public string $type,
        public string $chain,
        public string $network,
        public string $baseAsset,
        public string $quoteCurrency,
        public string $pairing,
        public float $exchangeRate,
        public float|array $amountRequested,
        public string $destinationAccount,
        public int $destinationTag,
        public ?int $expiry = null,
        public ?string $hash = null,
        public ?string $ctid = null,
        public float|array|null $amountPaid = null,
    ) {
        self::assertAmountShape($chain, $baseAsset, $amountRequested, 'amount_requested');

        if ($amountPaid !== null) {
            self::assertAmountShape($chain, $baseAsset, $amountPaid, 'amount_paid');
        }
    }

    /**
     * Builds a fresh v1 quote — the "quote part" a PriceService fills in.
     * No hash/amount_paid yet; those arrive via withFulfillment().
     */
    public static function quote(
        string $type,
        string $chain,
        string $network,
        string $baseAsset,
        string $quoteCurrency,
        string $pairing,
        float $exchangeRate,
        float|array $amountRequested,
        string $destinationAccount,
        int $destinationTag,
        ?int $expiry = null,
    ): self {
        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            type: $type,
            chain: $chain,
            network: $network,
            baseAsset: $baseAsset,
            quoteCurrency: $quoteCurrency,
            pairing: $pairing,
            exchangeRate: $exchangeRate,
            amountRequested: $amountRequested,
            destinationAccount: $destinationAccount,
            destinationTag: $destinationTag,
            expiry: $expiry,
        );
    }

    /**
     * Returns a new instance with the "fulfillment part" set — what a
     * SyncService fills in once a matching XRPL transaction is found.
     * The record is immutable, so this does not mutate $this.
     *
     * $ctid is the XRPL Compact Transaction ID — unlike $hash it also
     * encodes the network, so it's the more reliable way to verify the
     * payment actually went through on the expected network. It's an
     * XRPL-specific concept, not a general blockchain one, so it's
     * optional here: a future non-XRPL chain fulfills without it.
     */
    public function withFulfillment(string $hash, float|array $amountPaid, ?string $ctid = null): self
    {
        return new self(
            schemaVersion: $this->schemaVersion,
            type: $this->type,
            chain: $this->chain,
            network: $this->network,
            baseAsset: $this->baseAsset,
            quoteCurrency: $this->quoteCurrency,
            pairing: $this->pairing,
            exchangeRate: $this->exchangeRate,
            amountRequested: $this->amountRequested,
            destinationAccount: $this->destinationAccount,
            destinationTag: $this->destinationTag,
            expiry: $this->expiry,
            hash: $hash,
            ctid: $ctid,
            amountPaid: $amountPaid,
        );
    }

    /**
     * The requested amount as a plain decimal string, whatever its shape - the number a
     * customer is asked to send, without the issuer/currency envelope of an issued currency.
     */
    public function amountRequestedValue(): string
    {
        return self::plainValue($this->amountRequested);
    }

    /**
     * The delivered amount as a plain decimal string, or null while nothing has arrived.
     */
    public function amountPaidValue(): ?string
    {
        return $this->amountPaid === null ? null : self::plainValue($this->amountPaid);
    }

    /**
     * @param float|array{currency: string, value: string, issuer: string} $amount
     */
    private static function plainValue(float|array $amount): string
    {
        if (is_array($amount)) {
            return (string) $amount['value'];
        }

        return self::plainDecimal(BigDecimal::of((string) $amount));
    }

    /**
     * A decimal without exponent notation or trailing fraction zeros ("100", "0.84", "0.000001").
     * Done on the string rather than via BigDecimal::stripTrailingZeros(), which not every
     * brick/math release in the supported range provides.
     *
     * @internal shared with SettlementPolicy
     */
    public static function plainDecimal(BigDecimal $value): string
    {
        $plain = (string) $value;

        return str_contains($plain, '.') ? rtrim(rtrim($plain, '0'), '.') : $plain;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('schema_version', $data)) {
            throw new InvalidArgumentException('Missing schema_version.');
        }

        if ($data['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported schema_version %s (only %d is supported).',
                var_export($data['schema_version'], true),
                self::SCHEMA_VERSION,
            ));
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field '{$field}'.");
            }
        }

        return new self(
            schemaVersion: $data['schema_version'],
            type: $data['type'],
            chain: $data['chain'],
            network: $data['network'],
            baseAsset: $data['base_asset'],
            quoteCurrency: $data['quote_currency'],
            pairing: $data['pairing'],
            exchangeRate: $data['exchange_rate'],
            amountRequested: $data['amount_requested'],
            destinationAccount: $data['destination_account'],
            destinationTag: $data['destination_tag'],
            expiry: $data['expiry'] ?? null,
            hash: $data['hash'] ?? null,
            ctid: $data['ctid'] ?? null,
            amountPaid: $data['amount_paid'] ?? $data['delivered_amount'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'type' => $this->type,
            'chain' => $this->chain,
            'network' => $this->network,
            'base_asset' => $this->baseAsset,
            'quote_currency' => $this->quoteCurrency,
            'pairing' => $this->pairing,
            'exchange_rate' => $this->exchangeRate,
            'amount_requested' => $this->amountRequested,
            'destination_account' => $this->destinationAccount,
            'destination_tag' => $this->destinationTag,
            'expiry' => $this->expiry,
            'hash' => $this->hash,
            'ctid' => $this->ctid,
            'amount_paid' => $this->amountPaid,
        ];
    }

    private static function assertAmountShape(string $chain, string $baseAsset, float|array $amount, string $field): void
    {
        $isNativeAsset = $baseAsset === (self::NATIVE_ASSET_BY_CHAIN[$chain] ?? null);

        if ($isNativeAsset === is_array($amount)) {
            throw new InvalidArgumentException(
                $isNativeAsset
                    ? "{$field} must be a float for native asset {$baseAsset} on chain {$chain}."
                    : "{$field} must be an IssuedCurrencyAmount array for base_asset {$baseAsset}."
            );
        }

        if (is_array($amount)) {
            foreach (['currency', 'value', 'issuer'] as $key) {
                if (!array_key_exists($key, $amount)) {
                    throw new InvalidArgumentException("{$field} is missing '{$key}' for base_asset {$baseAsset}.");
                }
            }
        }
    }
}
