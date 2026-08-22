<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

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

    private const KNOWN_ASSETS = ['XRP', 'RLUSD', 'USDC'];

    private const STABLECOIN_ASSETS = ['RLUSD', 'USDC'];

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
        self::assertAmountShape($baseAsset, $amountRequested, 'amount_requested');

        if ($amountPaid !== null) {
            self::assertAmountShape($baseAsset, $amountPaid, 'amount_paid');
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

    private static function assertAmountShape(string $baseAsset, float|array $amount, string $field): void
    {
        if (!in_array($baseAsset, self::KNOWN_ASSETS, true)) {
            throw new InvalidArgumentException("Unknown base_asset '{$baseAsset}'.");
        }

        $isStablecoin = in_array($baseAsset, self::STABLECOIN_ASSETS, true);

        if ($isStablecoin !== is_array($amount)) {
            throw new InvalidArgumentException(
                $isStablecoin
                    ? "{$field} must be an IssuedCurrencyAmount array for base_asset {$baseAsset}."
                    : "{$field} must be a float for base_asset {$baseAsset}."
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
