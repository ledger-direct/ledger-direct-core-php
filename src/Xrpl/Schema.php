<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

/**
 * The two tables the core needs, as data — the "SQL/DDL as a constant or
 * migration template" INVARIANTS.md ("Tables") promises. The core never
 * creates them and never references a physical name; a platform prepends
 * its own prefix and creates them through its own DB layer.
 *
 * tables() is the neutral description a Laravel migration or a Doctrine
 * Table can be built from without a type translation table. mysql() is
 * the ready-made DDL for the MySQL-based platforms, and it reproduces the
 * PrestaShop adapter's schema — the one adapter whose DDL carried every
 * 0.4 requirement — apart from prefix and engine.
 *
 * Column types: 'int' and 'bigint' (unsigned where flagged), 'string' with
 * a length, 'text' for the JSON blobs. Nothing else is needed.
 */
final class Schema
{
    public const TABLE_TX = 'ledger_direct_xrpl_tx';
    public const TABLE_DESTINATION_TAG = 'ledger_direct_xrpl_destination_tag';

    /**
     * @return array<string, array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * }>
     */
    public static function tables(): array
    {
        return [
            self::TABLE_TX => [
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'unsigned' => true, 'nullable' => false, 'autoincrement' => true],
                    // A ledger index only means anything within one network; the
                    // sync cursor is scoped by (destination, network).
                    ['name' => 'network', 'type' => 'string', 'length' => 16, 'nullable' => false],
                    // BIGINT, not VARCHAR: the cursor is a MAX() and "9" sorts above "10".
                    ['name' => 'ledger_index', 'type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'hash', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    ['name' => 'ctid', 'type' => 'string', 'length' => 16, 'nullable' => false],
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
    }

    /**
     * One `CREATE TABLE IF NOT EXISTS` per table, in tables() order, with
     * $prefix in front of every table name (e.g. `ps_`, `wp_`).
     *
     * @return list<string>
     */
    public static function mysql(string $prefix = '', string $engine = 'InnoDB', string $charset = 'utf8mb4'): array
    {
        $statements = [];

        foreach (self::tables() as $table => $definition) {
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
