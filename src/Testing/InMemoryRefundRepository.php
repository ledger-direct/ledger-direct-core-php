<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use InvalidArgumentException;

/**
 * In-memory RefundRepositoryInterface for the standalone test harness, and the reference for
 * what an adapter's implementation does: rows from Refund::toRow(), read with
 * Refund::fromRow(), four unique rules, an update that checks the version.
 *
 * Keeps rows the way a database driver hands them back — every integer as a string — so a test
 * on this repository also exercises the reading an adapter's repository will do.
 */
final class InMemoryRefundRepository implements RefundRepositoryInterface
{
    /** @var array<string, array<string, string|null>> rows by id, in insertion order */
    private array $rows = [];

    /**
     * @param string $chain the chain whose table this is
     */
    public function __construct(private readonly string $chain = 'XRPL')
    {
    }

    public function insert(Refund $refund): bool
    {
        $row = $this->stored($refund);

        if (isset($this->rows[$row['id']]) || $this->violatesUnique($row)) {
            return false;
        }

        $this->rows[(string) $row['id']] = $row;

        return true;
    }

    public function update(Refund $refund, int $expectedVersion): bool
    {
        $row = $this->stored($refund);
        $current = $this->rows[$row['id']] ?? null;

        if ($current === null || $current['version'] !== (string) $expectedVersion || $this->violatesUnique($row)) {
            return false;
        }

        $this->rows[(string) $row['id']] = $row;

        return true;
    }

    public function find(string $id): ?Refund
    {
        return isset($this->rows[$id]) ? Refund::fromRow($this->rows[$id], $this->chain) : null;
    }

    public function findByReference(string $network, string $reference): ?Refund
    {
        return $this->select(
            static fn (array $row): bool => $row['network'] === $network && $row['reference'] === $reference,
        )[0] ?? null;
    }

    public function findBySource(string $network, string $sourceHash): array
    {
        $refunds = $this->select(
            static fn (array $row): bool => $row['network'] === $network && $row['source_hash'] === $sourceHash,
        );
        usort($refunds, static fn (Refund $a, Refund $b): int => $a->serial <=> $b->serial);

        return $refunds;
    }

    public function findByPlatformReference(string $platformReference): array
    {
        return self::oldestFirst($this->select(
            static fn (array $row): bool => $row['platform_reference'] === $platformReference,
        ));
    }

    public function findByDestination(string $network, string $payingAccount, string $destination): array
    {
        return self::oldestFirst($this->select(
            static fn (array $row): bool => $row['network'] === $network
                && $row['paying_account'] === $payingAccount
                && $row['destination'] === $destination,
        ));
    }

    public function findWatched(string $network, string $payingAccount, int $closedSince): array
    {
        return self::oldestFirst($this->select(
            static fn (array $row): bool => $row['network'] === $network
                && $row['paying_account'] === $payingAccount
                && self::isWatched($row, $closedSince),
        ));
    }

    public function watchedAccounts(string $network, int $closedSince): array
    {
        $accounts = [];
        foreach ($this->rows as $row) {
            if ($row['network'] === $network && self::isWatched($row, $closedSince)) {
                $accounts[(string) $row['paying_account']] = true;
            }
        }

        return array_map('strval', array_keys($accounts));
    }

    /**
     * Test control: the stored rows, as a database would return them.
     *
     * @return list<array<string, string|null>>
     */
    public function storedRows(): array
    {
        return array_values($this->rows);
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function isWatched(array $row, int $closedSince): bool
    {
        return $row['status'] === Refund::STATUS_AWAITING_EXECUTION
            || ($row['closed_at'] !== null && (int) $row['closed_at'] >= $closedSince);
    }

    /**
     * The four unique indexes of the port contract, against every other row.
     *
     * @param array<string, string|null> $row
     */
    private function violatesUnique(array $row): bool
    {
        foreach ($this->rows as $other) {
            if ($other['id'] === $row['id']) {
                continue;
            }

            $sameNumber = $other['network'] === $row['network']
                && $other['source_hash'] === $row['source_hash']
                && $other['serial'] === $row['serial'];
            // Null never collides, as in a unique index.
            $sameOpenKey = $row['open_key'] !== null && $other['open_key'] === $row['open_key'];
            $sameExecution = $row['executed_hash'] !== null && $other['executed_hash'] === $row['executed_hash'];
            $sameReference = $other['network'] === $row['network'] && $other['reference'] === $row['reference'];

            if ($sameNumber || $sameOpenKey || $sameExecution || $sameReference) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(array<string, string|null>): bool $where
     * @return list<Refund>
     */
    private function select(callable $where): array
    {
        return array_map(
            fn (array $row): Refund => Refund::fromRow($row, $this->chain),
            array_values(array_filter($this->rows, $where)),
        );
    }

    /**
     * @param list<Refund> $refunds
     * @return list<Refund>
     */
    private static function oldestFirst(array $refunds): array
    {
        // strcmp(), not <=>: two ids that look like numbers ("0e…1", "00…0") compare as numbers.
        usort(
            $refunds,
            static fn (Refund $a, Refund $b): int => $a->createdAt <=> $b->createdAt ?: strcmp($a->id, $b->id),
        );

        return $refunds;
    }

    /**
     * The row does not say which chain it is from: the table does. A refund of another chain
     * in this table would read back as this chain's.
     *
     * @return array<string, string|null>
     */
    private function stored(Refund $refund): array
    {
        if ($refund->chain !== $this->chain) {
            throw new InvalidArgumentException("This repository stores {$this->chain} refunds; the refund is on {$refund->chain}.");
        }

        return array_map(
            static fn (string|int|null $value): ?string => $value === null ? null : (string) $value,
            $refund->toRow(),
        );
    }
}
