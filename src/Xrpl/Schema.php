<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use InvalidArgumentException;

/**
 * The two tables the core needs, as data — the "SQL/DDL as a constant or
 * migration template" INVARIANTS.md ("Tables") promises. The core never
 * creates them and never references a physical name; a platform prepends
 * its own prefix and creates them through its own DB layer.
 *
 * tables() is the neutral description a Laravel migration or a Doctrine
 * Table can be built from without a type translation table. mysql() is
 * the ready-made DDL for the MySQL-based platforms. It started as the
 * PrestaShop adapter's schema — the one adapter whose DDL carried every
 * 0.4 requirement — and has since renamed `ctid` to `tx_ctid`, which the
 * adapters follow.
 *
 * Column names must work on every database a platform may run on. In
 * particular none may be one of PostgreSQL's system column names
 * (POSTGRES_SYSTEM_COLUMNS), which no user column can take, quoted or not.
 *
 * Column types: 'int' and 'bigint' (unsigned where flagged), 'string' with
 * a length, 'text' for the JSON blobs. Nothing else is needed.
 */
final class Schema
{
    public const TABLE_TX = 'ledger_direct_xrpl_tx';
    public const TABLE_DESTINATION_TAG = 'ledger_direct_xrpl_destination_tag';

    /**
     * Xahau keeps the same columns in tables of its own: its own transactions
     * and its own tag counter, never a row shared with the XRP Ledger, even
     * for an account that exists on both (INVARIANTS.md, "Xahau", Tables).
     */
    public const TABLE_XAHAU_TX = 'ledger_direct_xahau_tx';
    public const TABLE_XAHAU_DESTINATION_TAG = 'ledger_direct_xahau_destination_tag';

    private const TABLES_BY_CHAIN = [
        'XRPL' => [self::TABLE_TX, self::TABLE_DESTINATION_TAG],
        'XAHAU' => [self::TABLE_XAHAU_TX, self::TABLE_XAHAU_DESTINATION_TAG],
    ];

    /**
     * Names PostgreSQL gives every table implicitly; a user column cannot
     * have them. Kept here so the rule is checked, not remembered — every
     * chain's schema is tested against it.
     */
    public const POSTGRES_SYSTEM_COLUMNS = ['tableoid', 'xmin', 'cmin', 'xmax', 'cmax', 'ctid'];

    /**
     * @return array<string, array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * }>
     * @param string $chain 'XRPL' (the default, unchanged since 0.9) or 'XAHAU'. Xahau's index
     *     names carry the chain: PostgreSQL wants an index name unique in the schema, not
     *     only in its table.
     */
    public static function tables(string $chain = 'XRPL'): array
    {
        [$txTable, $tagTable] = self::TABLES_BY_CHAIN[$chain]
            ?? throw new InvalidArgumentException("Unsupported chain '{$chain}'.");

        $tables = [
            self::TABLE_TX => [
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'unsigned' => true, 'nullable' => false, 'autoincrement' => true],
                    // A ledger index only means anything within one network; the
                    // sync cursor is scoped by (destination, network).
                    ['name' => 'network', 'type' => 'string', 'length' => 16, 'nullable' => false],
                    // BIGINT, not VARCHAR: the cursor is a MAX() and "9" sorts above "10".
                    ['name' => 'ledger_index', 'type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'hash', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    // The CTID. Not called `ctid`: PostgreSQL reserves that name for a
                    // system column in every table, quoted or not.
                    ['name' => 'tx_ctid', 'type' => 'string', 'length' => 16, 'nullable' => false],
                    ['name' => 'account', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    ['name' => 'destination', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    // Unsigned: XRPL's DestinationTag is 0..4294967295 and the tag
                    // service generates across that whole range.
                    ['name' => 'destination_tag', 'type' => 'int', 'unsigned' => true, 'nullable' => true],
                    ['name' => 'date', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'meta', 'type' => 'text', 'nullable' => false],
                    ['name' => 'tx', 'type' => 'text', 'nullable' => false],
                ],
                'primary' => ['id'],
                'unique' => [
                    'uniq_ledger_direct_hash' => ['hash'],
                ],
                'indexes' => [
                    'idx_ledger_direct_destination' => ['destination', 'destination_tag'],
                    'idx_ledger_direct_ledger_index' => ['ledger_index'],
                    'idx_ledger_direct_cursor' => ['destination', 'network', 'ledger_index'],
                ],
            ],
            self::TABLE_DESTINATION_TAG => [
                'columns' => [
                    ['name' => 'destination_account', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    // One row per receiving account, a counter that starts at a
                    // random offset up to 2^31-1 and only ever grows — so unsigned
                    // 32-bit, with room. This table must survive uninstall.
                    ['name' => 'sequence', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                ],
                'primary' => ['destination_account'],
                'unique' => [],
                'indexes' => [],
            ],
        ];

        if ($chain === 'XRPL') {
            return $tables;
        }

        $rename = static fn (array $named): array => array_combine(
            array_map(static fn (string $name): string => str_replace('ledger_direct_', 'ledger_direct_xahau_', $name), array_keys($named)),
            array_values($named),
        );

        $tx = $tables[self::TABLE_TX];
        $tx['unique'] = $rename($tx['unique']);
        $tx['indexes'] = $rename($tx['indexes']);

        return [
            $txTable => $tx,
            $tagTable => $tables[self::TABLE_DESTINATION_TAG],
        ];
    }

    /**
     * One `CREATE TABLE IF NOT EXISTS` per table, in tables() order, with
     * $prefix in front of every table name (e.g. `ps_`, `wp_`), for the given chain's tables.
     *
     * @return list<string>
     */
    public static function mysql(string $prefix = '', string $engine = 'InnoDB', string $charset = 'utf8mb4', string $chain = 'XRPL'): array
    {
        $statements = [];

        foreach (self::tables($chain) as $table => $definition) {
            $lines = [];

            foreach ($definition['columns'] as $column) {
                $lines[] = '    `' . $column['name'] . '` ' . self::mysqlType($column);
            }

            $lines[] = '    PRIMARY KEY (' . self::columnList($definition['primary']) . ')';

            foreach ($definition['unique'] as $name => $columns) {
                $lines[] = '    UNIQUE KEY `' . $name . '` (' . self::columnList($columns) . ')';
            }

            foreach ($definition['indexes'] as $name => $columns) {
                $lines[] = '    KEY `' . $name . '` (' . self::columnList($columns) . ')';
            }

            $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $prefix . $table . "` (\n"
                . implode(",\n", $lines) . "\n"
                . ') ENGINE=' . $engine . ' DEFAULT CHARSET=' . $charset . ';';
        }

        return $statements;
    }

    /**
     * @param array{name: string, type: string, length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool} $column
     */
    private static function mysqlType(array $column): string
    {
        $type = match ($column['type']) {
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'string' => 'VARCHAR(' . $column['length'] . ')',
            'text' => 'LONGTEXT',
        };

        if ($column['unsigned'] ?? false) {
            $type .= ' UNSIGNED';
        }

        $type .= $column['nullable'] ? ' NULL DEFAULT NULL' : ' NOT NULL';

        if ($column['autoincrement'] ?? false) {
            $type .= ' AUTO_INCREMENT';
        }

        return $type;
    }

    /**
     * @param list<string> $columns
     */
    private static function columnList(array $columns): string
    {
        return implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
    }
}
