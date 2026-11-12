<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use InvalidArgumentException;

/**
 * The payment request the QR code encodes: one code with the receiving
 * account, the destination tag and — once the format is verified — the
 * amount, so the customer types nothing. Typing the tag was the biggest
 * source of lost payments.
 *
 * Built on the server so it is unit-testable and specified once for every
 * platform (INVARIANTS.md, "Payment request"); the page script only renders it.
 *
 * The form is the one Xaman's parser (xumm-string-decode) accepts:
 * `https://xrplf.org//send?to=<account>&dt=<tag>&amount=<amount>`, for a
 * token with `currency=<hex>&issuer=<address>`, and always `network=<key>`. Verified by scanning with
 * Xaman on the testnet (2026-09-28, PW-04): `amount` is read as the XRP
 * decimal the page shows — not as drops — and a token request takes the
 * currency and issuer over. So the request carries exactly the amount the
 * customer sees, the shortfall while a partial payment is in.
 */
final class PaymentUri
{
    public const BASE = 'https://xrplf.org//send';

    /** No amount in the request: the customer enters it from the page. */
    public const AMOUNT_NONE = 'none';

    /** The amount as the page shows it — XRP for the native asset, the token value otherwise. */
    public const AMOUNT_DISPLAYED = 'displayed';

    /** Since the scan test (PW-04): the amount as displayed. AMOUNT_NONE stays available for a platform that has not verified its wallets. */
    public const AMOUNT_MODE = self::AMOUNT_DISPLAYED;

    /**
     * Xaman's network keys (xaman-app, src/common/constants/network.ts), by chain
     * and network. The same r-address is valid on the XRP Ledger and on Xahau, and
     * Xaman pays a request without a network on whichever network it is on: an XRP
     * request scanned while Xaman was on Xahau opened an XAH payment of the same
     * amount (2026-11-12). With the key Xaman offers the switch instead (scan test
     * on the Xahau testnet, 2026-11-06). So every request names its network.
     */
    public const NETWORK_KEYS = [
        'XRPL' => [
            'mainnet' => 'MAINNET',
            'testnet' => 'TESTNET',
        ],
        'XAHAU' => [
            'mainnet' => 'XAHAU',
            'testnet' => 'XAHAUTESTNET',
        ],
    ];

    /** @deprecated since 0.11.3, use NETWORK_KEYS['XAHAU'] */
    public const XAHAU_NETWORK_KEYS = self::NETWORK_KEYS['XAHAU'];

    /**
     * @param string $amountDue the amount to send, as the page shows it: the
     *     shortfall while a partial payment is in, the request otherwise
     */
    public static function forIntent(PaymentIntent $intent, string $amountDue, string $amountMode = self::AMOUNT_MODE): string
    {
        $parameters = [
            'to' => $intent->destinationAccount,
            'dt' => (string) $intent->destinationTag,
        ];

        if ($amountMode === self::AMOUNT_DISPLAYED && $amountDue !== '') {
            $parameters['amount'] = $amountDue;
        }

        if (is_array($intent->amountRequested)) {
            $parameters['currency'] = (string) $intent->amountRequested['currency'];
            $parameters['issuer'] = (string) $intent->amountRequested['issuer'];
        }

        $parameters['network'] = self::NETWORK_KEYS[$intent->chain][$intent->network]
            ?? throw new InvalidArgumentException("Unsupported network '{$intent->network}' on chain '{$intent->chain}'.");

        return self::BASE . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
