<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigDecimal;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * The preconditions a Stellar payment has that XRPL does not (INVARIANTS.md,
 * "Stellar", Preconditions): the receiving account must exist, it must
 * hold a trust line for the asset with room for the amount, and the issuer
 * may carry flags a merchant should know about. Read-only, over Horizon's
 * account record, cached so a checkout render does not cost a Horizon call.
 *
 * What the adapter does with the answers is its business: hide an asset
 * the account cannot receive, show the issuer's flags as a one-liner.
 */
final class AccountInspector
{
    private const CACHE_KEY_PREFIX = 'ledger-direct.stellar.account.v1';

    public const DEFAULT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly HorizonClient $horizon,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
    ) {
    }

    public function accountExists(string $account, string $network): bool
    {
        return $this->accountRecord($account, $network) !== null;
    }

    /**
     * The account's trust line for the asset, or null when it has none —
     * a payment would then fail with op_no_trust and never arrive.
     */
    public function trustline(string $account, string $assetCode, string $issuer, string $network): ?Trustline
    {
        $record = $this->accountRecord($account, $network);

        foreach ($record['balances'] ?? [] as $balance) {
            if (($balance['asset_code'] ?? null) === $assetCode && ($balance['asset_issuer'] ?? null) === $issuer) {
                return new Trustline(
                    assetCode: $assetCode,
                    issuer: $issuer,
                    balance: (string) ($balance['balance'] ?? '0'),
                    limit: (string) ($balance['limit'] ?? '0'),
                    authorized: ($balance['is_authorized'] ?? true) !== false,
                );
            }
        }

        return null;
    }

    /**
     * Whether the account can receive $amount of the asset right now:
     * trust line present, authorized, and the limit leaves room. Pass no
     * amount to check the trust line alone.
     */
    public function canReceive(string $account, string $assetCode, string $issuer, string $network, ?string $amount = null): bool
    {
        $trustline = $this->trustline($account, $assetCode, $issuer, $network);

        if ($trustline === null || !$trustline->authorized) {
            return false;
        }

        return $amount === null || BigDecimal::of($trustline->headroom())->isGreaterThanOrEqualTo(BigDecimal::of($amount));
    }

    /**
     * What the issuer can do to balances in its asset — the flags a
     * merchant selling self-custody deserves to see spelled out.
     */
    public function issuerFlags(string $issuer, string $network): IssuerFlags
    {
        $record = $this->accountRecord($issuer, $network);
        $flags = $record['flags'] ?? [];

        return new IssuerFlags(
            authRequired: (bool) ($flags['auth_required'] ?? false),
            authRevocable: (bool) ($flags['auth_revocable'] ?? false),
            clawbackEnabled: (bool) ($flags['auth_clawback_enabled'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountRecord(string $account, string $network): ?array
    {
        $key = implode('.', [self::CACHE_KEY_PREFIX, $network, $account]);

        $cached = $this->cacheGet($key);
        if (is_array($cached)) {
            return $cached['record'];
        }

        $record = $this->horizon->account($account, $network);
        $this->cacheSet($key, ['record' => $record]);

        return $record;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache?->get($key);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Stellar account cache read failed', ['key' => $key, 'exception' => $exception->getMessage()]);

            return null;
        }
    }

    private function cacheSet(string $key, array $value): void
    {
        try {
            $this->cache?->set($key, $value, $this->cacheTtlSeconds);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Stellar account cache write failed', ['key' => $key, 'exception' => $exception->getMessage()]);
        }
    }
}
