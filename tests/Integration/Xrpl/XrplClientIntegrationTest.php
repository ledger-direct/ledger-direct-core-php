<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Xrpl;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use PHPUnit\Framework\TestCase;

/**
 * Hits the real XRPL testnet JSON-RPC endpoint. Not part of the default
 * (unit) test run — see README.md for how to run the "integration" suite
 * deliberately.
 */
final class XrplClientIntegrationTest extends TestCase
{
    // Long-lived, high-volume public testnet account used throughout XRPL's
    // own docs/tutorials as the standard example account.
    private const WELL_KNOWN_TESTNET_ACCOUNT = 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe';

    public function testFetchAccountTransactionsAndThenTxAgainstRealTestnet(): void
    {
        $xrplClient = new XrplClient(new Client(), new HttpFactory(), new HttpFactory());

        $page = $xrplClient->fetchAccountTransactions(self::WELL_KNOWN_TESTNET_ACCOUNT, 'testnet');

        self::assertIsArray($page['transactions']);

        if ($page['transactions'] === []) {
            self::markTestSkipped('Well-known testnet account returned zero transactions right now.');
        }

        $hash = $page['transactions'][0]['tx']['hash'] ?? null;
        self::assertIsString($hash);

        $tx = $xrplClient->tx($hash, 'testnet');

        self::assertIsArray($tx);
        self::assertArrayHasKey('validated', $tx);
    }

    public function testTxReturnsNullForAHashThatDoesNotExist(): void
    {
        $xrplClient = new XrplClient(new Client(), new HttpFactory(), new HttpFactory());

        $result = $xrplClient->tx(str_repeat('0', 64), 'testnet');

        self::assertNull($result);
    }
}
