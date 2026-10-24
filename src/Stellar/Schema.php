<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

/**
 * The two Stellar tables as data plus MySQL DDL — the sibling of
 * {@see \Hardcastle\LedgerDirect\Core\Xrpl\Schema}, same shapes, same rules
 * (INVARIANTS.md, "Stellar", Tables). The platform prefixes and creates them.
 *
 * Why the identity is (hash, op_index) and not the operation id, and why
 * there is no paging_token column, is explained there and not repeated here.
 */
final class Schema
{
    public const TABLE_PAYMENT = 'ledger_direct_stellar_payment';
    public const TABLE_MEMO = 'ledger_direct_stellar_memo';

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
            self::TABLE_PAYMENT => [
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'unsigned' => true, 'nullable' => false, 'autoincrement' => true],
                    ['name' => 'network', 'type' => 'string', 'length' => 16, 'nullable' => false],
                    // The TOID: ledger << 32 | tx order << 12 | op index. Orders rows, is the cursor,
                    // and repeats after a testnet reset — which is why it is not the unique key.
                    ['name' => 'payment_id', 'type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'hash', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    ['name' => 'op_index', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'type', 'type' => 'string', 'length' => 32, 'nullable' => false],
                    ['name' => 'account', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    ['name' => 'destination', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    // The resolved identifier (muxed id, MEMO_ID or canonical MEMO_TEXT); a foreign
                    // one can use the full uint64, so unsigned BIGINT and read back as a string.
                    ['name' => 'memo_id', 'type' => 'bigint', 'unsigned' => true, 'nullable' => true],
                    ['name' => 'asset_code', 'type' => 'string', 'length' => 12, 'nullable' => false],
                    ['name' => 'asset_issuer', 'type' => 'string', 'length' => 64, 'nullable' => true],
                    // Decimal string with up to seven places, as Horizon sends it — never a float.
                    ['name' => 'amount', 'type' => 'string', 'length' => 32, 'nullable' => false],
                    ['name' => 'created_at', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                    ['name' => 'raw', 'type' => 'text', 'nullable' => false],
                ],
                'primary' => ['id'],
                'unique' => [
                    'uniq_ledger_direct_stellar_op' => ['hash', 'op_index'],
                ],
                'indexes' => [
                    'idx_ledger_direct_stellar_memo' => ['destination', 'memo_id'],
                    'idx_ledger_direct_stellar_cursor' => ['destination', 'network', 'payment_id'],
                ],
            ],
            self::TABLE_MEMO => [
                'columns' => [
                    ['name' => 'destination_account', 'type' => 'string', 'length' => 64, 'nullable' => false],
                    ['name' => 'sequence', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                ],
                'primary' => ['destination_account'],
                'unique' => [],
                'indexes' => [],
            ],
        ];
    }

    /**
     * One `CREATE TABLE IF NOT EXISTS` per table, in tables() order.
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
