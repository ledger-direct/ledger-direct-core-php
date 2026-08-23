<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Port\TransactionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Syncs an XRPL account's incoming transactions into storage, deduplicated
 * against what's already there, and matches a stored transaction against a
 * destination account/tag (the "Sync + Dedup + Match" service CLAUDE.md
 * names).
 */
final class SyncService
{
    private const MAX_PAGES = 1000;

    public function __construct(
        private readonly XrplClient $xrplClient,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches new incoming transactions for $address since the last sync
     * and stores them, paginating until exhausted (capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies).
     */
    public function syncTransactions(string $address, string $network): void
    {
        $lastSyncedLedgerIndex = $this->transactionRepository->getLastSyncedLedgerIndex();
        $afterLedgerIndex = $lastSyncedLedgerIndex !== null ? (int) $lastSyncedLedgerIndex : null;

        $marker = null;
        $pages = 0;

        do {
            $page = $this->xrplClient->fetchAccountTransactions($address, $network, $afterLedgerIndex, $marker);

            if ($page['transactions'] !== []) {
                $this->storeIncomingNewTransactions($page['transactions'], $address);
            }

            $marker = $page['marker'];
            $pages++;
        } while ($marker !== null && $pages < self::MAX_PAGES);
    }

    /**
     * The "Match" part: finds an already-synced transaction for a
     * destination account/tag pair. A thin delegate — exists so a platform
     * integration has one service for the whole "did this order get paid"
     * flow instead of needing TransactionRepositoryInterface injected
     * separately just for this.
     */
    public function findTransaction(string $destinationAccount, int $destinationTag): ?XrplTransaction
    {
        return $this->transactionRepository->findTransaction($destinationAccount, $destinationTag);
    }

    /**
     * @param array<int, array<string, mixed>> $rawTransactions
     */
    private function storeIncomingNewTransactions(array $rawTransactions, string $ownAddress): void
    {
        $incoming = array_values(array_filter(
            $rawTransactions,
            static fn (array $raw): bool => ($raw['tx']['Destination'] ?? null) === $ownAddress,
        ));

        if ($incoming === []) {
            return;
        }

        $hashes = array_map(static fn (array $raw): string => (string) ($raw['tx']['hash'] ?? ''), $incoming);
        $existingHashes = $this->transactionRepository->findExistingHashes($hashes);

        $new = array_values(array_filter(
            $incoming,
            static fn (array $raw): bool => !in_array($raw['tx']['hash'] ?? '', $existingHashes, true),
        ));

        if ($new === []) {
            return;
        }

        $hydrated = [];
        foreach ($new as $raw) {
            try {
                $hydrated[] = self::hydrate($raw);
            } catch (Throwable $exception) {
                $this->logger->warning('LedgerDirect: skipping malformed synced transaction', [
                    'hash' => $raw['tx']['hash'] ?? null,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($hydrated !== []) {
            $this->transactionRepository->saveTransactions($hydrated);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function hydrate(array $raw): XrplTransaction
    {
        $tx = $raw['tx'] ?? throw new UnexpectedValueException("Missing 'tx' in synced transaction.");

        foreach (['ledger_index', 'hash', 'ctid', 'Account', 'Destination', 'date'] as $requiredField) {
            if (!isset($tx[$requiredField])) {
                throw new UnexpectedValueException("Synced transaction is missing required field '{$requiredField}'.");
            }
        }

        return new XrplTransaction(
            ledgerIndex: (string) $tx['ledger_index'],
            hash: (string) $tx['hash'],
            ctid: (string) $tx['ctid'],
            account: (string) $tx['Account'],
            destination: (string) $tx['Destination'],
            destinationTag: isset($tx['DestinationTag']) ? (int) $tx['DestinationTag'] : null,
            date: (int) $tx['date'],
            meta: $raw['meta'] ?? [],
            tx: $tx,
        );
    }
}
