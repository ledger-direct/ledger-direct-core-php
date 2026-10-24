<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Stellar\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Stellar tables to INVARIANTS.md, "Stellar" — Tables. Each
 * assertion names a rule the M0 spike showed to matter.
 */
final class SchemaTest extends TestCase
{
    public function testThePaymentTableIsOneRowPerOperationKeyedByHashAndIndex(): void
    {
        $payment = Schema::tables()[Schema::TABLE_PAYMENT];
        $columns = array_column($payment['columns'], null, 'name');

        self::assertSame(
            ['id', 'network', 'payment_id', 'hash', 'op_index', 'type', 'account', 'destination', 'memo_id', 'asset_code', 'asset_issuer', 'amount', 'created_at', 'raw'],
            array_keys($columns),
        );

        // Operation ids repeat after a testnet reset; (hash, op_index) does not.
        self::assertSame(['hash', 'op_index'], $payment['unique']['uniq_ledger_direct_stellar_op']);
        self::assertArrayNotHasKey('paging_token', $columns, 'paging_token equals the operation id on the payments endpoint');

        // The TOID orders rows and is the cursor, scoped by account and network.
        self::assertSame('bigint', $columns['payment_id']['type']);
        self::assertTrue($columns['payment_id']['unsigned']);
        self::assertSame(['destination', 'network', 'payment_id'], $payment['indexes']['idx_ledger_direct_stellar_cursor']);

        // A foreign identifier can use the full uint64.
        self::assertSame('bigint', $columns['memo_id']['type']);
        self::assertTrue($columns['memo_id']['unsigned']);
        self::assertTrue($columns['memo_id']['nullable']);
        self::assertSame(['destination', 'memo_id'], $payment['indexes']['idx_ledger_direct_stellar_memo']);

        // Amounts are decimal strings, never floats.
        self::assertSame('string', $columns['amount']['type']);
        self::assertTrue($columns['asset_issuer']['nullable'], 'native has no issuer');
    }

    public function testTheMemoCounterTableMirrorsTheXrplCounter(): void
    {
        $memo = Schema::tables()[Schema::TABLE_MEMO];

        self::assertSame(['destination_account', 'sequence'], array_column($memo['columns'], 'name'));
        self::assertSame(['destination_account'], $memo['primary']);
        self::assertTrue($memo['columns'][1]['unsigned']);
    }

    public function testMysqlDdlCarriesTheContractualKeys(): void
    {
        [$payment, $memo] = Schema::mysql('sw_');

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `sw_ledger_direct_stellar_payment` (', $payment);
        self::assertStringContainsString('`payment_id` BIGINT UNSIGNED NOT NULL', $payment);
        self::assertStringContainsString('`memo_id` BIGINT UNSIGNED NULL DEFAULT NULL', $payment);
        self::assertStringContainsString('`amount` VARCHAR(32) NOT NULL', $payment);
        self::assertStringContainsString('UNIQUE KEY `uniq_ledger_direct_stellar_op` (`hash`, `op_index`)', $payment);
        self::assertStringContainsString('KEY `idx_ledger_direct_stellar_cursor` (`destination`, `network`, `payment_id`)', $payment);
        self::assertStringEndsWith(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $payment);

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `sw_ledger_direct_stellar_memo` (', $memo);
        self::assertStringContainsString('`sequence` INT UNSIGNED NOT NULL', $memo);
    }
}
