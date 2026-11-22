<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use InvalidArgumentException;

/**
 * The refund table, as data: one per chain, `ledger_direct_xrpl_refund` and
 * `ledger_direct_xahau_refund`, in the shape of {@see Schema::tables()}. An entry of its own,
 * not part of Schema::tables(): an adapter that creates its tables from Schema keeps creating
 * exactly the two it always did, and adds this one when it adopts refunds.
 *
 * The columns are those of Refund::toRow() (Refund::COLUMNS); the test holds the two together.
 *
 * Four unique indexes carry rules that only storage can hold — the numbering per incoming
 * payment, the open key, the execution hash, the reference; see Port\RefundRepositoryInterface.
 *
 * **The table is a record.** It must survive uninstall, and nothing may empty it: what it holds
 * is not on the ledger and cannot be synced back (INVARIANTS.md, "Refund").
 */
final class RefundSchema
{
    public const TABLE = 'ledger_direct_xrpl_refund';
    public const TABLE_XAHAU = 'ledger_direct_xahau_refund';

    /**
     * @return array<string, array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * }>
     * @param string $chain 'XRPL' or 'XAHAU'. The index names carry the table's name: PostgreSQL
     *     wants an index name unique in the schema, not only in its table.
     */
    public static function tables(string $chain = 'XRPL'): array
    {
        $table = match ($chain) {
            'XRPL' => self::TABLE,
            'XAHAU' => self::TABLE_XAHAU,
            default => throw new InvalidArgumentException("Unsupported chain '{$chain}'."),
        };

        $string = static fn (string $name, int $length, bool $nullable = false): array
            => ['name' => $name, 'type' => 'string', 'length' => $length, 'nullable' => $nullable];
        // Unix times and counters.
        $int = static fn (string $name, bool $nullable = false): array
            => ['name' => $name, 'type' => 'int', 'unsigned' => true, 'nullable' => $nullable];
        // Ledger indexes, like the transaction table's.
        $bigint = static fn (string $name, bool $nullable = false): array
            => ['name' => $name, 'type' => 'bigint', 'unsigned' => true, 'nullable' => $nullable];
        $text = static fn (string $name, bool $nullable = false): array
            => ['name' => $name, 'type' => 'text', 'nullable' => $nullable];

        return [
            $table => [
                'columns' => [
                    $string('id', 32),
                    $int('schema_version'),
                    $string('network', 16),
                    // Plain text, to be read at a glance: Refund::STATUSES, ::PURPOSES.
                    $string('status', 32),
                    $string('purpose', 32),
                    $string('platform_reference', 64),
                    $string('source_hash', 64),
                    $int('serial'),
                    // The account the request asks to pay from, next to the one that paid.
                    $string('paying_account', 64),
                    $string('destination', 64),
                    // Unsigned 32-bit, and nullable: no tag is not tag 0.
                    $int('destination_tag', nullable: true),
                    $string('destination_origin', 16),
                    $string('destination_attestation', 32, nullable: true),
                    // The native asset's code or the token's currency code; a null issuer marks the native asset.
                    $string('asset_currency', 40),
                    $string('asset_issuer', 64, nullable: true),
                    // Decimal strings, never a float column. A token value can run to 16
                    // significant digits at an exponent of -96.
                    $string('amount', 128),
                    $string('fiat_amount', 32, nullable: true),
                    $string('fiat_currency', 8, nullable: true),
                    $string('reference', 64),
                    $text('reason', nullable: true),
                    $int('created_at'),
                    $string('created_by', 190, nullable: true),
                    $bigint('created_ledger'),
                    $string('executed_hash', 64, nullable: true),
                    $bigint('executed_ledger', nullable: true),
                    $int('executed_at', nullable: true),
                    $string('executed_account', 64, nullable: true),
                    $string('delivered_amount', 128, nullable: true),
                    $string('matched_by', 16, nullable: true),
                    // '' or Refund::DEVIATIONS, comma-separated: `deviation <> ''` finds every one.
                    $string('deviation', 64),
                    $int('closed_at', nullable: true),
                    $int('booking_claimed_until', nullable: true),
                    $int('booked_at', nullable: true),
                    $text('booking_note', nullable: true),
                    $text('findings'),
                    $string('open_key', 64, nullable: true),
                    $int('version'),
                ],
                'primary' => ['id'],
                'unique' => [
                    'uniq_' . $table . '_source' => ['network', 'source_hash', 'serial'],
                    'uniq_' . $table . '_open_key' => ['open_key'],
                    'uniq_' . $table . '_executed_hash' => ['executed_hash'],
                    'uniq_' . $table . '_reference' => ['network', 'reference'],
                ],
                'indexes' => [
                    'idx_' . $table . '_platform' => ['platform_reference'],
                    'idx_' . $table . '_destination' => ['network', 'paying_account', 'destination'],
                    'idx_' . $table . '_watched' => ['network', 'status', 'closed_at'],
                ],
            ],
        ];
    }

    /**
     * `CREATE TABLE IF NOT EXISTS` for the chain's refund table, with $prefix in front of the
     * table name, as {@see Schema::mysql()} does for the other two.
     *
     * @return list<string>
     */
    public static function mysql(string $prefix = '', string $engine = 'InnoDB', string $charset = 'utf8mb4', string $chain = 'XRPL'): array
    {
        return Schema::mysqlFor(self::tables($chain), $prefix, $engine, $charset);
    }
}
