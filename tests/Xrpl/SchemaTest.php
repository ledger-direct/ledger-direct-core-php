<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Pins the schema to INVARIANTS.md, "Tables". Every assertion here names a
 * rule an adapter once got wrong.
 */
final class SchemaTest extends TestCase
{
    public function testTheTransactionTableCarriesEveryContractualColumnAndIndex(): void
    {
        $tx = Schema::tables()[Schema::TABLE_TX];
        $columns = array_column($tx['columns'], null, 'name');

        self::assertSame(
            ['id', 'network', 'ledger_index', 'hash', 'tx_ctid', 'account', 'destination', 'destination_tag', 'date', 'meta', 'tx'],
            array_keys($columns),
        );

        // A ledger index only means anything within one network.
        self::assertSame(['type' => 'string', 'length' => 16, 'nullable' => false], self::shape($columns['network']));
        // BIGINT, not VARCHAR: the cursor is a MAX() and "9" sorts above "10".
        self::assertSame('bigint', $columns['ledger_index']['type']);
        self::assertTrue($columns['ledger_index']['unsigned']);
        // XRPL's DestinationTag is 0..4294967295.
        self::assertSame('int', $columns['destination_tag']['type']);
        self::assertTrue($columns['destination_tag']['unsigned']);
        self::assertTrue($columns['destination_tag']['nullable']);

        // A real CTID is 16 characters: "C", 7 hex ledger index, 4 transaction index, 4 network id.
        self::assertSame(['type' => 'string', 'length' => 16, 'nullable' => false], self::shape($columns['tx_ctid']));

        self::assertSame(['hash'], $tx['unique']['uniq_ledger_direct_hash']);
        self::assertSame(['destination', 'destination_tag'], $tx['indexes']['idx_ledger_direct_destination']);
        self::assertSame(['destination', 'network', 'ledger_index'], $tx['indexes']['idx_ledger_direct_cursor']);
    }

    /**
     * PostgreSQL gives every table the system columns tableoid, xmin, cmin,
     * xmax, cmax and ctid, and no user column may take those names — quoting
     * does not help. The transaction table once had a `ctid` column and could
     * not be created on PostgreSQL at all.
     */
    public function testNoColumnIsAPostgresSystemColumn(): void
    {
        foreach (Schema::tables() as $table => $definition) {
            foreach ($definition['columns'] as $column) {
                self::assertNotContains(
                    strtolower($column['name']),
                    Schema::POSTGRES_SYSTEM_COLUMNS,
                    "{$table}.{$column['name']} is a PostgreSQL system column name",
                );
            }
        }
    }

    public function testTheCounterTableIsOneUnsignedRowPerAccount(): void
    {
        $counter = Schema::tables()[Schema::TABLE_DESTINATION_TAG];

        self::assertSame(['destination_account', 'sequence'], array_column($counter['columns'], 'name'));
        self::assertSame(['destination_account'], $counter['primary']);
        self::assertTrue($counter['columns'][1]['unsigned']);
        self::assertFalse($counter['columns'][1]['nullable']);
    }

    /**
     * The PrestaShop adapter's DDL was the reference — the one adapter whose
     * schema carried every 0.4 requirement — so the MySQL output matches it
     * apart from prefix, engine and the `tx_ctid` column the adapters follow.
     */
    public function testMysqlDdlMatchesThePrestaShopReference(): void
    {
        [$tx, $counter] = Schema::mysql('ps_', 'InnoDB');

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ledger_direct_xrpl_tx` (', $tx);
        self::assertStringContainsString('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT', $tx);
        self::assertStringContainsString('`network` VARCHAR(16) NOT NULL', $tx);
        self::assertStringContainsString('`ledger_index` BIGINT UNSIGNED NOT NULL', $tx);
        self::assertStringContainsString('`hash` VARCHAR(64) NOT NULL', $tx);
        self::assertStringContainsString('`tx_ctid` VARCHAR(16) NOT NULL', $tx);
        self::assertStringNotContainsString('`ctid`', $tx);
        self::assertStringContainsString('`destination_tag` INT UNSIGNED NULL DEFAULT NULL', $tx);
        self::assertStringContainsString('`meta` LONGTEXT NOT NULL', $tx);
        self::assertStringContainsString('PRIMARY KEY (`id`)', $tx);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_hash` (`hash`)', $tx);
        self::assertStringContainsString('KEY `idx_ledger_direct_destination` (`destination`, `destination_tag`)', $tx);
        self::assertStringContainsString('KEY `idx_ledger_direct_ledger_index` (`ledger_index`)', $tx);
        self::assertStringContainsString('KEY `idx_ledger_direct_cursor` (`destination`, `network`, `ledger_index`)', $tx);
        self::assertStringEndsWith(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $tx);

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ledger_direct_xrpl_destination_tag` (', $counter);
        self::assertStringContainsString('`destination_account` VARCHAR(64) NOT NULL', $counter);
        self::assertStringContainsString('`sequence` INT UNSIGNED NOT NULL', $counter);
        self::assertStringContainsString('PRIMARY KEY (`destination_account`)', $counter);
    }

    public function testThePrefixIsOptional(): void
    {
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ledger_direct_xrpl_tx` (', Schema::mysql()[0]);
    }

    /**
     * @param array{name: string, type: string, length?: int, unsigned?: bool, nullable: bool} $column
     * @return array<string, mixed>
     */
    private static function shape(array $column): array
    {
        unset($column['name']);

        return $column;
    }
}
