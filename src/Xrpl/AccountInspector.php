<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Whether a receiving account on the XRP Ledger or Xahau can take a payment (INVARIANTS.md,
 * "Receiving account"): activated, no deposit authorization in the way, and for RLUSD and USDC
 * a trust line to the registry's issuer that is not frozen, authorized where the issuer asks for
 * it, and has room. Read-only over `account_info`, `account_lines` and `server_state`, cached so
 * a checkout render does not cost a node call.
 *
 * Found on the testnet on 2026-11-17 (Documentation/Mainnet-Testing.md, K3): without this, a
 * shop offered RLUSD to an account without a trust line, and the customer's payment failed with
 * tecPATH_DRY after the order existed.
 */
final class AccountInspector implements ReceivingAccountCheck
{
    public const DEFAULT_CACHE_TTL_SECONDS = 300;

    /** Reserves change by validator vote, rarely; an hour keeps a checkout from asking each time. */
    private const RESERVES_TTL_SECONDS = 3600;

    private const CACHE_KEY_PREFIX = 'ld.xrpl.inspect.v1';

    /** AccountRoot flags (https://xrpl.org/docs/references/protocol/ledger-data/ledger-entry-types/accountroot). */
    private const LSF_REQUIRE_AUTH = 0x00040000;

    private const LSF_GLOBAL_FREEZE = 0x00400000;

    private const LSF_DEPOSIT_AUTH = 0x01000000;

    /** XRP and XAH have six decimals: a drop is a millionth. */
    private const NATIVE_DECIMALS = 6;

    private readonly StablecoinRegistry $registry;

    /**
     * What one assess() read, by cache key — so its checks use the data it fetched, whether or
     * not the cache took it. Null outside assess().
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $read = null;

    /**
     * @param string $chain 'XRPL' or 'XAHAU', as the XrplClient it reads through
     */
    public function __construct(
        private readonly XrplClient $client,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        private readonly string $chain = ChainCatalog::XRPL,
        ?StablecoinRegistry $registry = null,
    ) {
        $this->registry = $registry ?? new StablecoinRegistry();
    }

    public function offers(string $account, string $asset, string $network, ?string $amount = null): bool
    {
        $native = $this->isNative($asset);

        try {
            if ($native) {
                if ($this->accountRoot($account, $network, false) !== null || $amount === null) {
                    return true;
                }

                // Not activated: the first payment activates the account if it reaches the base reserve.
                return BigDecimal::of($amount)->isGreaterThanOrEqualTo(BigDecimal::of($this->baseReserve($network)));
            }

            return $this->canReceive($account, $asset, $network, $amount);
        } catch (LedgerDirectException $exception) {
            $this->logger->warning('LedgerDirect: could not check the receiving account; ' . ($native ? 'offering the native asset' : 'leaving the issued asset out'), [
                'chain' => $this->chain,
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
        $this->read = [];
        try {
            return new Assessment($account, $network, $this->findings($account, $network, $assets, $fresh));
        } catch (LedgerDirectException $exception) {
            return new Assessment($account, $network, [
                new Finding(Finding::NOT_CHECKED, null, false, ['error' => $exception->getMessage()]),
            ]);
        } finally {
            $this->read = null;
        }
    }

    /**
     * Whether the account can receive the asset right now — the ledger's answer, without the
     * checkout's leniency. For an issued asset without $amount: a usable line with any room.
     *
     * @throws XrplRpcException when the node cannot be asked
     */
    public function canReceive(string $account, string $asset, string $network, ?string $amount = null): bool
    {
        if ($this->isNative($asset)) {
            if ($this->accountRoot($account, $network, false) !== null) {
                return true;
            }

            return $amount !== null && BigDecimal::of($amount)->isGreaterThanOrEqualTo(BigDecimal::of($this->baseReserve($network)));
        }

        return $this->tokenFindings($account, $asset, $network, false, $amount) === [];
    }

    /**
     * The trust line to the registry's issuer of $asset, or null when the account has none.
     *
     * @throws XrplRpcException
     */
    public function trustline(string $account, string $asset, string $network, bool $fresh = false): ?Trustline
    {
        ['currency' => $currency, 'issuer' => $issuer] = $this->issued($asset, $network);

        foreach ($this->accountLines($account, $network, $fresh) as $line) {
            if (($line['account'] ?? null) === $issuer && self::sameCurrency((string) ($line['currency'] ?? ''), $currency)) {
                return Trustline::fromAccountLine($line);
            }
        }

        return null;
    }

    /**
     * The base reserve in the native asset's units, e.g. "1" (XRP or XAH).
     *
     * @throws XrplRpcException
     */
    public function baseReserve(string $network): string
    {
        $key = $this->key('reserves', $network, '');
        $cached = $this->cacheGet($key);
        $drops = is_array($cached) && isset($cached['base']) ? (string) $cached['base'] : null;

        if ($drops === null) {
            $reserves = $this->client->reserves($network);
            $drops = $reserves['base'];
            $this->cacheSet($key, $reserves, self::RESERVES_TTL_SECONDS);
        }

        // A million drops to the unit: the drops are the unscaled value at scale 6, no rounding.
        return PaymentIntent::plainDecimal(BigDecimal::ofUnscaledValue($drops, self::NATIVE_DECIMALS));
    }

    /**
     * @param list<string> $assets
     * @return list<Finding>
     */
    private function findings(string $account, string $network, array $assets, bool $fresh): array
    {
        $findings = [];
        $root = $this->accountRoot($account, $network, $fresh);
        if ($fresh && $root !== null) {
            // Read the lines and the issuers afresh once; the per-asset checks below then read
            // them from the cache instead of asking the node once per asset.
            $this->accountLines($account, $network, true);
            foreach ($assets as $asset) {
                if (!$this->isNative($asset)) {
                    $this->accountRoot($this->issued($asset, $network)['issuer'], $network, true);
                }
            }
        }

        if ($root === null) {
            $findings[] = new Finding(Finding::ACCOUNT_MISSING, null, false, ['reserve' => $this->baseReserve($network)]);
        } elseif ($this->hasFlag($root, self::LSF_DEPOSIT_AUTH)) {
            $findings[] = new Finding(Finding::DEPOSIT_AUTH, null, false);
        }

        foreach ($assets as $asset) {
            if (!$this->isNative($asset)) {
                array_push($findings, ...$this->tokenFindings($account, $asset, $network, false, null));
            }
        }

        return $findings;
    }

    /**
     * What keeps the issued asset from arriving; empty when nothing does.
     *
     * @return list<Finding>
     */
    private function tokenFindings(string $account, string $asset, string $network, bool $fresh, ?string $amount): array
    {
        ['issuer' => $issuer] = $this->issued($asset, $network);
        $context = ['issuer' => $issuer];

        if ($this->accountRoot($account, $network, $fresh) === null) {
            return [new Finding(Finding::TRUSTLINE_MISSING, $asset, true, $context)];
        }

        $issuerRoot = $this->accountRoot($issuer, $network, $fresh);
        if ($issuerRoot !== null && $this->hasFlag($issuerRoot, self::LSF_GLOBAL_FREEZE)) {
            return [new Finding(Finding::ISSUER_FROZEN, $asset, true, $context)];
        }

        $line = $this->trustline($account, $asset, $network, $fresh);
        if ($line === null) {
            return [new Finding(Finding::TRUSTLINE_MISSING, $asset, true, $context)];
        }

        $findings = [];
        if ($line->frozen) {
            $findings[] = new Finding(Finding::TRUSTLINE_FROZEN, $asset, true, $context);
        }
        if ($issuerRoot !== null && $this->hasFlag($issuerRoot, self::LSF_REQUIRE_AUTH) && !$line->authorized) {
            $findings[] = new Finding(Finding::TRUSTLINE_NOT_AUTHORIZED, $asset, true, $context);
        }

        $headroom = BigDecimal::of($line->headroom());
        $needed = $amount === null ? null : BigDecimal::of($amount);
        if ($needed === null ? $headroom->isZero() : $headroom->isLessThan($needed)) {
            $findings[] = new Finding(Finding::TRUSTLINE_FULL, $asset, true, $context + ['headroom' => $line->headroom()]);
        }

        return $findings;
    }

    /**
     * @return array{currency: string, issuer: string}
     */
    private function issued(string $asset, string $network): array
    {
        $amount = match ($this->chain . ':' . $asset) {
            ChainCatalog::XRPL . ':RLUSD' => $this->registry->getRLUSDAmount($network, '0'),
            ChainCatalog::XRPL . ':USDC' => $this->registry->getUSDCAmount($network, '0'),
            default => throw new InvalidArgumentException("No issued asset '{$asset}' on {$this->chain}."),
        };

        return ['currency' => (string) $amount['currency'], 'issuer' => (string) $amount['issuer']];
    }

    /**
     * XRPL currency codes: a three-character code is case-sensitive (`USD` and `usd` are two
     * currencies); a 40-character hex code is the same in either letter case.
     */
    private static function sameCurrency(string $onLine, string $wanted): bool
    {
        return strlen($wanted) === 40 ? strcasecmp($onLine, $wanted) === 0 : $onLine === $wanted;
    }

    private function isNative(string $asset): bool
    {
        foreach (ChainCatalog::chain($this->chain)->assets as $descriptor) {
            if ($descriptor->code === $asset) {
                return $descriptor->native;
            }
        }

        throw new InvalidArgumentException("No asset '{$asset}' on {$this->chain}.");
    }

    /**
     * @param array<string, mixed> $root
     */
    private function hasFlag(array $root, int $flag): bool
    {
        return ((int) ($root['Flags'] ?? 0) & $flag) === $flag;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountRoot(string $account, string $network, bool $fresh): ?array
    {
        $key = $this->key('root', $network, $account);
        if (isset($this->read[$key])) {
            return $this->read[$key]['root'];
        }
        $cached = $fresh ? null : $this->cacheGet($key);
        if (is_array($cached) && array_key_exists('root', $cached)) {
            return $cached['root'];
        }

        $root = $this->client->accountInfo($account, $network);
        // "Not activated" is cached too: a checkout render must not ask the node for it each time.
        $this->cacheSet($key, ['root' => $root], $this->cacheTtlSeconds);
        if ($this->read !== null) {
            $this->read[$key] = ['root' => $root];
        }

        return $root;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountLines(string $account, string $network, bool $fresh): array
    {
        $key = $this->key('lines', $network, $account);
        if (isset($this->read[$key])) {
            return $this->read[$key]['lines'];
        }
        $cached = $fresh ? null : $this->cacheGet($key);
        if (is_array($cached) && isset($cached['lines']) && is_array($cached['lines'])) {
            return $cached['lines'];
        }

        $lines = $this->client->accountLines($account, $network);
        $this->cacheSet($key, ['lines' => $lines], $this->cacheTtlSeconds);
        if ($this->read !== null) {
            $this->read[$key] = ['lines' => $lines];
        }

        return $lines;
    }

    private function key(string $kind, string $network, string $account): string
    {
        // PSR-16 guarantees keys of 64 characters; the account goes in as a short digest.
        return implode('.', [self::CACHE_KEY_PREFIX, strtolower($this->chain), $network, $kind, substr(hash('sha256', $account), 0, 16)]);
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache?->get($key);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: account inspector cache read failed', ['key' => $key, 'exception' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function cacheSet(string $key, array $value, int $ttl): void
    {
        try {
            $this->cache?->set($key, $value, $ttl);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: account inspector cache write failed', ['key' => $key, 'exception' => $exception->getMessage()]);
        }
    }
}
