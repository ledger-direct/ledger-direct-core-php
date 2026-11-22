<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Refund\DestinationReport;
use Hardcastle\LedgerDirect\Core\Refund\RefundNotice;

/**
 * Asks the ledger, right now, about an account a refund would go to: does it require a
 * destination tag, and would it accept this amount in this asset.
 *
 * Not {@see AccountInspector}: that one answers for the shop's own receiving account and the
 * assets of the registry, from a cache a checkout can afford. A refund goes to somebody else's
 * account, in whatever asset the customer paid with, and a five-minute-old answer to "does this
 * account require a tag" is not an answer. Nothing here is cached.
 *
 * @internal used by RefundService
 */
final class RefundDestinationCheck
{
    /** AccountRoot flags (https://xrpl.org/docs/references/protocol/ledger-data/ledger-entry-types/accountroot). */
    private const LSF_REQUIRE_DEST_TAG = 0x00020000;

    private const LSF_REQUIRE_AUTH = 0x00040000;

    private const LSF_GLOBAL_FREEZE = 0x00400000;

    private const LSF_DEPOSIT_AUTH = 0x01000000;

    /** `TransferRate` is in billionths of what the destination receives; this is "no fee". */
    private const TRANSFER_RATE_NONE = 1000000000;

    public function __construct(private readonly XrplClient $client)
    {
    }

    /**
     * @throws XrplRpcException when the node cannot be asked
     */
    public function check(string $account, string $network, LedgerAmount $amount): DestinationReport
    {
        $root = $this->client->accountInfo($account, $network);

        if ($root === null) {
            return new DestinationReport($account, false, false, [$this->notActivated($network, $amount)]);
        }

        $notices = [];
        if (self::hasFlag($root, self::LSF_DEPOSIT_AUTH)) {
            // Fails unless the destination pre-authorized the paying account, which nobody can see from here.
            $notices[] = new RefundNotice(RefundNotice::DESTINATION_DEPOSIT_AUTH, false);
        }

        if (!$amount->isNative()) {
            array_push($notices, ...$this->tokenNotices($account, $network, $amount));
        }

        return new DestinationReport($account, true, self::hasFlag($root, self::LSF_REQUIRE_DEST_TAG), $notices);
    }

    /**
     * An account that is not on the ledger. The native asset creates it if the payment reaches
     * the base reserve; a token cannot arrive at all.
     */
    private function notActivated(string $network, LedgerAmount $amount): RefundNotice
    {
        if (!$amount->isNative()) {
            return new RefundNotice(RefundNotice::DESTINATION_NOT_ACTIVATED, true);
        }

        $reserve = LedgerAmount::fromWire($this->client->reserves($network)['base'])->value;
        $activates = BigDecimal::of($amount->value)->isGreaterThanOrEqualTo(BigDecimal::of($reserve));

        return new RefundNotice(RefundNotice::DESTINATION_NOT_ACTIVATED, !$activates, ['reserve' => $reserve]);
    }

    /**
     * @return list<RefundNotice>
     */
    private function tokenNotices(string $account, string $network, LedgerAmount $amount): array
    {
        $issuer = (string) $amount->issuer;
        $context = ['currency' => (string) $amount->currency, 'issuer' => $issuer];
        $issuerRoot = $this->client->accountInfo($issuer, $network);
        $notices = [];

        if ($issuerRoot !== null && self::hasFlag($issuerRoot, self::LSF_GLOBAL_FREEZE)) {
            $notices[] = new RefundNotice(RefundNotice::ISSUER_FROZEN, true, $context);
        }

        $rate = $issuerRoot['TransferRate'] ?? null;
        if (is_int($rate) && $rate > self::TRANSFER_RATE_NONE) {
            // The request names the amount to deliver and Xaman sets SendMax to the same amount
            // (scanned 2026-11-20): with a fee on top, that payment does not go through.
            $notices[] = new RefundNotice(RefundNotice::TRANSFER_FEE, false, $context + ['rate' => (string) $rate]);
        }

        $line = $this->trustline($account, $network, $amount);
        if ($line === null) {
            $notices[] = new RefundNotice(RefundNotice::TRUSTLINE_MISSING, true, $context);

            return $notices;
        }

        if ($line->frozen) {
            $notices[] = new RefundNotice(RefundNotice::TRUSTLINE_FROZEN, false, $context);
        }

        if ($issuerRoot !== null && self::hasFlag($issuerRoot, self::LSF_REQUIRE_AUTH) && !$line->authorized) {
            $notices[] = new RefundNotice(RefundNotice::TRUSTLINE_NOT_AUTHORIZED, true, $context);
        }

        if (BigDecimal::of($line->headroom())->isLessThan(BigDecimal::of($amount->value))) {
            $notices[] = new RefundNotice(RefundNotice::TRUSTLINE_FULL, true, $context + ['headroom' => $line->headroom()]);
        }

        return $notices;
    }

    private function trustline(string $account, string $network, LedgerAmount $amount): ?Trustline
    {
        foreach ($this->client->accountLines($account, $network, $amount->issuer) as $line) {
            $currency = (string) ($line['currency'] ?? '');
            // A node writes a 40-digit code in upper case; LedgerAmount keeps it that way.
            $currency = strlen($currency) === 40 ? strtoupper($currency) : $currency;

            if (($line['account'] ?? null) === $amount->issuer && $currency === $amount->currency) {
                return Trustline::fromAccountLine($line);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $root
     */
    private static function hasFlag(array $root, int $flag): bool
    {
        return ((int) ($root['Flags'] ?? 0) & $flag) === $flag;
    }
}
