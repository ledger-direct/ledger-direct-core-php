<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
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
        private readonly XrplTransactionRepositoryInterface $transactionRepository,
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
        $lastSyncedLedgerIndex = $this->transactionRepository->getLastSyncedLedgerIndex($address, $network);
        $afterLedgerIndex = $lastSyncedLedgerIndex !== null ? (int) $lastSyncedLedgerIndex : null;

        try {
            $this->syncFrom($address, $network, $afterLedgerIndex);
        } catch (XrplRpcException $exception) {
            /*
             * The testnet is reset periodically, which rewinds its ledger
             * index. Rows from the previous epoch then sit far above the
             * live ledger, the cursor built from them is unservable, and
             * rippled answers lgrIdxsInvalid — for every order, forever,
             * until somebody deletes rows by hand. Recover by doing the one
             * thing that is guaranteed to be servable: ask again with no
             * cursor. findExistingHashes() already keeps that from
             * duplicating anything.
             *
             * Only retried when a cursor was actually sent; without one,
             * lgrIdxsInvalid means something else entirely and retrying the
             * identical call would just fail the same way.
             */
            if ($afterLedgerIndex === null || $exception->error !== XrplClient::ERROR_LEDGER_INDEXES_INVALID) {
                throw $exception;
            }

            $this->logger->warning(
                'LedgerDirect: stored ledger cursor is ahead of the network, resyncing without one',
                [
                    'address' => $address,
                    'network' => $network,
                    'cursor' => $afterLedgerIndex,
                ],
            );

            $this->syncFrom($address, $network, null);
        }
    }

    /**
     * One pass of the paginated fetch-and-store loop, capped at MAX_PAGES —
     * the same safety-guard shape used elsewhere in the core for an
     * otherwise-open-ended loop against external/injected dependencies.
     */
    private function syncFrom(string $address, string $network, ?int $afterLedgerIndex): void
    {
        $marker = null;
        $pages = 0;

        do {
            $page = $this->xrplClient->fetchAccountTransactions($address, $network, $afterLedgerIndex, $marker);

            if ($page['transactions'] !== []) {
                $this->storeIncomingNewTransactions($page['transactions'], $address, $network);
            }

            $marker = $page['marker'];
            $pages++;
        } while ($marker !== null && $pages < self::MAX_PAGES);
    }

    /**
     * Every synced transaction on this destination account/tag, newest
     * first. A thin delegate — exists so a platform integration has one
     * service for the whole "did this order get paid" flow instead of
     * needing XrplTransactionRepositoryInterface injected separately just
     * for this. Use it to *show* what arrived on a tag; use
     * findTransactionFor() to decide which one pays an order.
     *
     * @return XrplTransaction[]
     */
    public function findTransactions(string $destinationAccount, int $destinationTag): array
    {
        return $this->transactionRepository->findTransactions($destinationAccount, $destinationTag);
    }

    /**
     * The "Match" part: which of the transactions on the intent's tag is
     * the one that pays it — the newest whose delivered amount has the same
     * asset *class* as what was quoted (a native float pays a native quote,
     * an issued-currency object pays an issued-currency quote).
     *
     * Selecting on class rather than taking the first row is the whole
     * point. A tag can carry a stray payment in the other class, and
     * PaymentIntent::withFulfillment() rejects a class mismatch outright —
     * so handing it the wrong candidate aborts the sync and leaves the
     * order unpaid forever, with the real payment sitting right there
     * unexamined.
     *
     * A candidate in the right class but from the **wrong issuer** is
     * deliberately still returned: that is a genuine payment attempt on
     * this order, and SettlementPolicy is what declares it non-settling
     * (with the full amount still outstanding), so the platform can tell
     * the customer their token was wrong instead of showing nothing.
     */
    public function findTransactionFor(PaymentIntent $intent): ?XrplTransaction
    {
        $quotedIssuedCurrency = is_array($intent->amountRequested);

        foreach ($this->findTransactions($intent->destinationAccount, $intent->destinationTag) as $candidate) {
            try {
                $delivered = $candidate->getDeliveredAmount();
            } catch (UnexpectedValueException $exception) {
                /*
                 * The ledger's own 'unavailable' marker, or a delivered
                 * amount of an unexpected type. Skip it rather than abort:
                 * a later candidate may well be the real payment.
                 */
                $this->logger->warning('LedgerDirect: skipping a transaction with an unreadable delivered amount', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($delivered === null) {
                // Carries a Destination but delivered nothing measurable — an
                // EscrowCreate or CheckCreate lands in the same table. Routine.
                $this->logger->debug('LedgerDirect: skipping a non-payment transaction on this tag', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                ]);

                continue;
            }

            if (is_array($delivered) !== $quotedIssuedCurrency) {
                $this->logger->warning('LedgerDirect: payment in a different asset class on this tag skipped', [
                    'hash' => $candidate->hash,
                    'destination_tag' => $intent->destinationTag,
                    'quoted_asset' => $intent->baseAsset,
                ]);

                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rawTransactions
     */
    private function storeIncomingNewTransactions(array $rawTransactions, string $ownAddress, string $network): void
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
                $hydrated[] = self::hydrate($raw, $network);
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
    private static function hydrate(array $raw, string $network): XrplTransaction
    {
        $tx = $raw['tx'] ?? throw new UnexpectedValueException("Missing 'tx' in synced transaction.");

        foreach (['ledger_index', 'hash', 'ctid', 'Account', 'Destination', 'date'] as $requiredField) {
            if (!isset($tx[$requiredField])) {
                throw new UnexpectedValueException("Synced transaction is missing required field '{$requiredField}'.");
            }
        }

        return new XrplTransaction(
            network: $network,
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
