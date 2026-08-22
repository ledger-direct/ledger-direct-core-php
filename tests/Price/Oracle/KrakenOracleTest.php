<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Price\Oracle;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Price\Oracle\KrakenOracle;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class KrakenOracleTest extends TestCase
{
    public function testReadsTheSingleResultEntryGenerically(): void
    {
        // Kraken keys the result by its own inconsistent pair naming (here:
        // 'XXRPZUSD') — the oracle must not hardcode that key and instead
        // read whichever single entry 'result' contains.
        $client = new FakeHttpClient();
        $client->queueResponse(
            'api.kraken.com',
            new Response(200, [], '{"error":[],"result":{"XXRPZUSD":{"c":["0.55123","100.0"]}}}'),
        );

        $oracle = new KrakenOracle($client, new HttpFactory());

        self::assertSame(0.55123, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testReadsAnyResultKeyEvenForDifferentlyNamedPairs(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(
            'api.kraken.com',
            new Response(200, [], '{"error":[],"result":{"USDCUSD":{"c":["1.0001","50.0"]}}}'),
        );

        $oracle = new KrakenOracle($client, new HttpFactory());

        self::assertSame(1.0001, $oracle->getCurrentPriceForPair('USDC', 'USD'));
    }

    public function testReturnsZeroOnEmptyResult(): void
    {
        $client = new FakeHttpClient();
        $client->queueResponse(
            'api.kraken.com',
            new Response(200, [], '{"error":["EQuery:Unknown asset pair"],"result":{}}'),
        );

        $oracle = new KrakenOracle($client, new HttpFactory());

        self::assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'ZZZ'));
    }
}
