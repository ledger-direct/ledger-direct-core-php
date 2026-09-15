<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Testing;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Test-only PSR-16 cache backed by a plain array. Honours TTLs against a
 * clock the test controls, so an entry can be aged without sleeping.
 *
 * failOn() makes get()/set() throw, standing in for an unreachable Redis:
 * the core must degrade to "no cache", never to "no payment".
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, ttl: int|null, expires_at: int|null}> */
    private array $entries = [];

    private int $offset = 0;

    private readonly ClockInterface $clock;

    /** @var array<string, true> */
    private array $failing = [];

    /**
     * @param ClockInterface|int|null $clock the clock TTLs are judged against. Pass the same
     *     FrozenClock the service under test uses, so advancing it ages the cache too; an int
     *     is a fixed starting timestamp; null is the wall clock.
     */
    public function __construct(ClockInterface|int|null $clock = null)
    {
        $this->clock = match (true) {
            $clock instanceof ClockInterface => $clock,
            is_int($clock) => new FrozenClock($clock),
            default => new SystemClock(),
        };
    }

    /** Moves the cache's own view of time forward, independent of the clock it was given. */
    public function advance(int $seconds): void
    {
        $this->offset += $seconds;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp() + $this->offset;
    }

    /**
     * Ages a stored entry by rewriting its timestamp, simulating time
     * having passed since it was written without moving anything else.
     */
    public function ageEntry(string $key, int $seconds): void
    {
        $entry = $this->entries[$key] ?? throw new RuntimeException("InMemoryCache: no entry for '{$key}'.");
        $entry['value']['fetched_at'] -= $seconds;
        $this->entries[$key] = $entry;
    }

    /** @param 'get'|'set' ...$operations */
    public function failOn(string ...$operations): void
    {
        foreach ($operations as $operation) {
            $this->failing[$operation] = true;
        }
    }

    /** The TTL the writer asked for, in seconds. */
    public function ttlFor(string $key): ?int
    {
        return $this->entries[$key]['ttl'] ?? null;
    }

    /** @return list<string> every key currently stored */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->failing['get'])) {
            throw new RuntimeException('InMemoryCache: backend unreachable.');
        }

        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return $default;
        }

        if ($entry['expires_at'] !== null && $entry['expires_at'] <= $this->now()) {
            unset($this->entries[$key]);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if (isset($this->failing['set'])) {
            throw new RuntimeException('InMemoryCache: backend unreachable.');
        }

        $this->entries[$key] = [
            'value' => $value,
            'ttl' => is_int($ttl) ? $ttl : null,
            'expires_at' => is_int($ttl) ? $this->now() + $ttl : null,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }
}
