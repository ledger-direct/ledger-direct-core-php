<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Tests\Fixtures\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase
{
    private const OWN_ADDRESS = 'rOwnAddress';

    public function testSyncStoresOnlyIncomingTransactions(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_IN', account: 'rSender', destination: self::OWN_ADDRESS),
            $this->rawTx(hash: 'HASH_OUT', account: self::OWN_ADDRESS, destination: 'rSomeoneElse'),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        self::assertSame(['HASH_IN'], $hashes);
    }

    public function testDoesNotDuplicateAnAlreadyStoredHash(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_EXISTING', ledgerIndex: '100')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            $this->rawTx(hash: 'HASH_EXISTING', account: 'rSender', destination: self::OWN_ADDRESS),
            $this->rawTx(hash: 'HASH_NEW', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        self::assertCount(2, $repository->storedTransactions());
    }

    public function testFollowsPaginationAcrossMultiplePages(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse(
            [$this->rawTx(hash: 'HASH_PAGE_1', account: 'rSender', destination: self::OWN_ADDRESS)],
            marker: 'page-2-marker',
        ));
        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse(
            [$this->rawTx(hash: 'HASH_PAGE_2', account: 'rSender', destination: self::OWN_ADDRESS)],
        ));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        sort($hashes);
        self::assertSame(['HASH_PAGE_1', 'HASH_PAGE_2'], $hashes);
    }

    public function testResumesFromTheLastSyncedLedgerIndex(): void
    {
        [$client, $repository, $service] = $this->makeService();

        $repository->saveTransactions([$this->hydrated(hash: 'HASH_OLD', ledgerIndex: '500')]);

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $sentBody = json_decode((string) $client->lastRequest()?->getBody(), true);
        self::assertSame(501, $sentBody['params'][0]['ledger_index_min']);
    }

    public function testSkipsAMalformedEntryAndLogsAWarningWithoutLosingTheRest(): void
    {
        [$client, $repository, $service, $logger] = $this->makeService();

        $client->queueResponse('s.altnet.rippletest.net', $this->accountTxResponse([
            ['tx' => ['Destination' => self::OWN_ADDRESS, 'Account' => 'rSender']], // missing hash, ledger_index, ...
            $this->rawTx(hash: 'HASH_VALID', account: 'rSender', destination: self::OWN_ADDRESS),
        ]));

        $service->syncTransactions(self::OWN_ADDRESS, 'testnet');

        $hashes = array_map(static fn (XrplTransaction $t): string => $t->hash, $repository->storedTransactions());
        self::assertSame(['HASH_VALID'], $hashes);
        self::assertSame(1, $logger->count());
        self::assertSame('warning', $logger->records()[0]['level']);
    }

    public function testFindTransactionDelegatesToTheRepository(): void
    {
        [, $repository, $service] = $this->makeService();

        $repository->saveTransactions([
            $this->hydrated(hash: 'HASH_MATCH', ledgerIndex: '1', destination: 'rDest', destinationTag: 123),
        ]);

        self::assertSame('HASH_MATCH', $service->findTransaction('rDest', 123)?->hash);
        self::assertNull($service->findTransaction('rDest', 999));
    }

    /**
     * @return array{0: FakeHttpClient, 1: InMemoryXrplTransactionRepository, 2: SyncService, 3: RecordingLogger}
     */
    private function makeService(): array
    {
        $client = new FakeHttpClient();
        $repository = new InMemoryXrplTransactionRepository();
        $logger = new RecordingLogger();
        $service = new SyncService(
            new XrplClient($client, new HttpFactory(), new HttpFactory()),
            $repository,
            $logger,
        );

        return [$client, $repository, $service, $logger];
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     */
    private function accountTxResponse(array $transactions, string|null $marker = null): Response
    {
        return new Response(200, [], json_encode([
            'result' => [
                'status' => 'success',
                'transactions' => $transactions,
                'marker' => $marker,
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawTx(string $hash, string $account, string $destination, ?int $destinationTag = null): array
    {
        return [
            'tx' => array_filter([
                'ledger_index' => 100,
                'hash' => $hash,
                'ctid' => 'C0000000000000000000000',
                'Account' => $account,
                'Destination' => $destination,
                'DestinationTag' => $destinationTag,
                'date' => 800000000,
            ], static fn ($value) => $value !== null),
            'meta' => [],
        ];
    }

    private function hydrated(
        string $hash,
        string $ledgerIndex,
        string $destination = self::OWN_ADDRESS,
        ?int $destinationTag = null,
    ): XrplTransaction {
        return new XrplTransaction(
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'C0000000000000000000000',
            account: 'rSender',
            destination: $destination,
            destinationTag: $destinationTag,
            date: 800000000,
            meta: [],
            tx: [],
        );
    }
}
