<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
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
 * The checkout asks offers(), the configuration screen assess() (INVARIANTS.md,
 * "Receiving account"); the primitives below stay for what else an adapter
 * shows, such as the issuer's flags as a one-liner.
 */
final class AccountInspector implements ReceivingAccountCheck
{
    private const CACHE_KEY_PREFIX = 'ledger-direct.stellar.account.v1';

    public const DEFAULT_CACHE_TTL_SECONDS = 300;

    private readonly StablecoinRegistry $registry;

    public function __construct(
        private readonly HorizonClient $horizon,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        ?StablecoinRegistry $registry = null,
    ) {
        $this->registry = $registry ?? new StablecoinRegistry();
    }

    /**
     * Unlike XRPL, a Stellar payment never creates its destination (op_no_destination): an
     * account that does not exist is offered nothing, whatever the amount.
     */
    public function offers(string $account, string $asset, string $network, ?string $amount = null): bool
    {
        $native = $asset === 'XLM';

        try {
            if ($native) {
                return $this->accountExists($account, $network);
            }

            return $this->canReceive($account, $asset, $this->registry->issuer($asset, $network), $network, $amount);
        } catch (LedgerDirectException $exception) {
            $this->logger->warning('LedgerDirect: could not check the Stellar receiving account; ' . ($native ? 'offering XLM' : 'leaving the issued asset out'), [
                'network' => $network,
                'account' => $account,
                'asset' => $asset,
                'exception' => $exception->getMessage(),
            ]);

            return $native;
        }
    }

    public function assess(string $account, string $network, array $assets, bool $fresh = true): Assessment
    {
        try {
            $record = $this->accountRecord($account, $network, $fresh);
        } catch (LedgerDirectException $exception) {
            return new Assessment($account, $network, [
                new Finding(Finding::NOT_CHECKED, null, false, ['error' => $exception->getMessage()]),
            ]);
        }

        if ($record === null) {
            // Nothing can arrive, XLM included: a blocking finding per asset, plus the account's own.
            $findings = [new Finding(Finding::ACCOUNT_MISSING, null, true)];
            foreach ($assets as $asset) {
                if ($asset !== 'XLM') {
                    $findings[] = new Finding(Finding::TRUSTLINE_MISSING, $asset, true, ['issuer' => $this->registry->issuer($asset, $network)]);
                }
            }

            return new Assessment($account, $network, $findings);
        }

        $findings = [];
        foreach ($assets as $asset) {
            if ($asset === 'XLM') {
                continue;
            }

            $issuer = $this->registry->issuer($asset, $network);
            $context = ['issuer' => $issuer];
            // From the record just read: no second Horizon call that could throw, no stale cache.
            $trustline = self::trustlineIn($record, $asset, $issuer);

            if ($trustline === null) {
                $findings[] = new Finding(Finding::TRUSTLINE_MISSING, $asset, true, $context);
                continue;
            }
            if (!$trustline->authorized) {
                $findings[] = new Finding(Finding::TRUSTLINE_NOT_AUTHORIZED, $asset, true, $context);
            }
            if (BigDecimal::of($trustline->headroom())->isZero()) {
                $findings[] = new Finding(Finding::TRUSTLINE_FULL, $asset, true, $context + ['headroom' => $trustline->headroom()]);
            }
        }

        return new Assessment($account, $network, $findings);
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
        return self::trustlineIn($this->accountRecord($account, $network), $assetCode, $issuer);
    }

    /**
     * @param array<string, mixed>|null $record Horizon's account record
     */
    private static function trustlineIn(?array $record, string $assetCode, string $issuer): ?Trustline
    {
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
     * trust line present, authorized, and the limit leaves room. Without an
     * amount, any room at all: a full line can receive nothing (op_line_full).
     */
    public function canReceive(string $account, string $assetCode, string $issuer, string $network, ?string $amount = null): bool
    {
        $trustline = $this->trustline($account, $assetCode, $issuer, $network);

        if ($trustline === null || !$trustline->authorized) {
            return false;
        }

        $room = BigDecimal::of($trustline->headroom());

        return $amount === null ? $room->isPositive() : $room->isGreaterThanOrEqualTo(BigDecimal::of($amount));
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
    private function accountRecord(string $account, string $network, bool $fresh = false): ?array
    {
        // PSR-16 guarantees keys of 64 characters; a 56-character account spelled out does not fit.
        $key = implode('.', [self::CACHE_KEY_PREFIX, $network, substr(hash('sha256', $account), 0, 16)]);

        // Fresh: read Horizon, and refresh what the checkout reads.
        $cached = $fresh ? null : $this->cacheGet($key);
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
