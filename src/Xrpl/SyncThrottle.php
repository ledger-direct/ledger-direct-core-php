<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Keeps a status endpoint from syncing the same receiving account with the
 * ledger more often than once per interval. Every sync is a node request
 * (measured ~1 s against ~0.09 s without), and an unauthenticated endpoint
 * polled every few seconds by every waiting customer is otherwise a free
 * amplifier against the merchant's own XRPL node.
 *
 * Keyed by network and account, not by order: the sync is per account, so
 * throttling per order would still let ten waiting customers trigger ten
 * node requests per window. Per account it is exactly one — and a second
 * call for the same order inside the window certainly does not sync,
 * which is what INVARIANTS.md ("Payment status") asks for.
 *
 * The store is the same PSR-16 cache the rate cache uses. A broken cache
 * degrades to "always sync", never to "never sync".
 */
final class SyncThrottle
{
    /** Same rule as the rate cache: the network must be in the key, dots are PSR-16-legal. */
    private const KEY_PREFIX = 'ledger-direct.sync.v1';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds = PaymentStatus::MIN_SYNC_INTERVAL_SECONDS,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Whether the account is due for a sync: no mark inside the interval,
     * or a cache that cannot say.
     */
    public function shouldSync(string $network, string $account): bool
    {
        try {
            return $this->cache->get(self::key($network, $account)) === null;
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: sync throttle read failed, syncing anyway', [
                'network' => $network,
                'account' => $account,
                'exception' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Records that the account was just synced; the mark expires with the
     * interval. A write failure is logged and otherwise ignored.
     */
    public function markSynced(string $network, string $account): void
    {
        try {
            $this->cache->set(
                self::key($network, $account),
                $this->clock->now()->getTimestamp(),
                $this->intervalSeconds,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: sync throttle write failed', [
                'network' => $network,
                'account' => $account,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The one-liner a status endpoint calls: sync if due, then mark.
     *
     * @return bool whether a sync actually ran
     */
    public function syncIfDue(SyncService $sync, string $account, string $network): bool
    {
        if (!$this->shouldSync($network, $account)) {
            return false;
        }

        $sync->syncTransactions($account, $network);
        $this->markSynced($network, $account);

        return true;
    }

    private static function key(string $network, string $account): string
    {
        return implode('.', [self::KEY_PREFIX, $network, $account]);
    }
}
