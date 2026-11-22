<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundSchema;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundSchemaTest extends TestCase
{
    /**
     * A column the record writes and the table lacks fails the insert on a real database, and
     * only there. So the two lists are held together here.
     */
    #[DataProvider('chains')]
    public function testTheTableHasExactlyTheColumnsARefundRowHas(string $chain, string $table): void
    {
        $definition = RefundSchema::tables($chain)[$table];

        self::assertSame(Refund::COLUMNS, array_column($definition['columns'], 'name'));
        self::assertSame(['id'], $definition['primary']);
    }

    /**
     * The four rules only storage can hold (Port\RefundRepositoryInterface).
     */
    #[DataProvider('chains')]
    public function testTheFourUniqueIndexesAreThere(string $chain, string $table): void
    {
        $unique = RefundSchema::tables($chain)[$table]['unique'];

        self::assertSame(
            [['network', 'source_hash', 'serial'], ['open_key'], ['executed_hash'], ['network', 'reference']],
            array_values($unique),
        );
        foreach (array_keys($unique) as $name) {
            self::assertStringContainsString($table, $name, 'PostgreSQL wants index names unique in the schema');
            self::assertLessThanOrEqual(63, strlen($name), 'PostgreSQL cuts an identifier at 63 bytes');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function chains(): array
    {
        return [
            'XRPL' => ['XRPL', RefundSchema::TABLE],
            'Xahau' => ['XAHAU', RefundSchema::TABLE_XAHAU],
        ];
    }

    public function testTheColumnsThatMustTellNoTagFromTagZeroAndOpenFromClosedAreNullable(): void
    {
        $columns = array_column(RefundSchema::tables()[RefundSchema::TABLE]['columns'], null, 'name');

        // No tag is not tag 0, and the tag's range is unsigned 32-bit.
        self::assertTrue($columns['destination_tag']['nullable']);
        self::assertTrue($columns['destination_tag']['unsigned']);
        self::assertSame('int', $columns['destination_tag']['type']);
        // Null once the refund is closed, so the unique index lets the key go.
        self::assertTrue($columns['open_key']['nullable']);
        self::assertTrue($columns['executed_hash']['nullable']);
        // A null issuer marks the native asset.
        self::assertTrue($columns['asset_issuer']['nullable']);
        // Amounts are decimal strings, never numbers.
        self::assertSame('string', $columns['amount']['type']);
        self::assertSame('string', $columns['delivered_amount']['type']);
        // `deviation <> ''` finds every execution that differs from its request.
        self::assertFalse($columns['deviation']['nullable']);
        // BIGINT like the transaction table's ledger_index.
        self::assertSame('bigint', $columns['created_ledger']['type']);
    }

    public function testNoColumnIsAPostgresSystemColumn(): void
    {
        foreach (['XRPL', 'XAHAU'] as $chain) {
            foreach (RefundSchema::tables($chain) as $table => $definition) {
                foreach ($definition['columns'] as $column) {
                    self::assertNotContains(
                        strtolower($column['name']),
                        Schema::POSTGRES_SYSTEM_COLUMNS,
                        "{$table}.{$column['name']} is a PostgreSQL system column name",
                    );
                }
            }
        }
    }

    public function testMysqlDdl(): void
    {
        [$ddl] = RefundSchema::mysql('wp_');

        self::assertCount(1, RefundSchema::mysql());
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `wp_ledger_direct_xrpl_refund` (', $ddl);
        self::assertStringContainsString('`id` VARCHAR(32) NOT NULL', $ddl);
        self::assertStringContainsString('`status` VARCHAR(32) NOT NULL', $ddl);
        self::assertStringContainsString('`destination_tag` INT UNSIGNED NULL DEFAULT NULL', $ddl);
        self::assertStringContainsString('`amount` VARCHAR(128) NOT NULL', $ddl);
        self::assertStringContainsString('`created_ledger` BIGINT UNSIGNED NOT NULL', $ddl);
        self::assertStringContainsString('`reason` LONGTEXT NULL DEFAULT NULL', $ddl);
        self::assertStringContainsString('`findings` LONGTEXT NOT NULL', $ddl);
        self::assertStringContainsString('`open_key` VARCHAR(64) NULL DEFAULT NULL', $ddl);
        self::assertStringContainsString('PRIMARY KEY (`id`)', $ddl);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_xrpl_refund_source` (`network`, `source_hash`, `serial`)', $ddl);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_xrpl_refund_open_key` (`open_key`)', $ddl);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_xrpl_refund_executed_hash` (`executed_hash`)', $ddl);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_xrpl_refund_reference` (`network`, `reference`)', $ddl);
        self::assertStringContainsString('KEY `idx_ledger_direct_xrpl_refund_platform` (`platform_reference`)', $ddl);
        self::assertStringContainsString('KEY `idx_ledger_direct_xrpl_refund_destination` (`network`, `paying_account`, `destination`)', $ddl);
        self::assertStringEndsWith(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $ddl);

        self::assertStringStartsWith(
            'CREATE TABLE IF NOT EXISTS `ledger_direct_xahau_refund` (',
            RefundSchema::mysql(chain: 'XAHAU')[0],
        );
    }

    /**
     * An adapter that creates its tables from Schema keeps creating exactly the two it always
     * did; the refund table is an entry of its own.
     */
    public function testThePaymentSchemaIsUnchanged(): void
    {
        self::assertSame([Schema::TABLE_TX, Schema::TABLE_DESTINATION_TAG], array_keys(Schema::tables()));
        self::assertCount(2, Schema::mysql());
    }

    public function testAnotherChainHasNoRefundTable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RefundSchema::tables('STELLAR');
    }
}
