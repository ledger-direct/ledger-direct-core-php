<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Payment;

use Hardcastle\LedgerDirect\Core\Refund\Refund;
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
        $token = is_array($intent->amountRequested);

        return self::build(
            to: $intent->destinationAccount,
            tag: $intent->destinationTag,
            amount: $amountMode === self::AMOUNT_DISPLAYED && $amountDue !== '' ? $amountDue : null,
            currency: $token ? (string) $intent->amountRequested['currency'] : null,
            issuer: $token ? (string) $intent->amountRequested['issuer'] : null,
            invoiceId: null,
            chain: $intent->chain,
            network: $intent->network,
        );
    }

    /**
     * The request a merchant signs to pay a refund: to the refund's destination, the exact
     * amount, and the refund's reference as `invoiceid`.
     *
     * Two differences from a customer's request, both on purpose. **`dt` is there only when the
     * refund has a tag**: a destination without one must not get tag 0 by default. And the
     * request carries `invoiceid`: Xaman copies it into the payment's `InvoiceID` field
     * (scanned on both testnets, 2026-11-20, also together with a tag and a token), and by that
     * field the core tells which refund a payment was meant for.
     */
    public static function forRefund(Refund $refund): string
    {
        return self::build(
            to: $refund->destination->account,
            tag: $refund->destination->tag,
            amount: $refund->amount->value,
            currency: $refund->amount->currency,
            issuer: $refund->amount->issuer,
            invoiceId: $refund->reference,
            chain: $refund->chain,
            network: $refund->network,
        );
    }

    /**
     * The one place the parameter names and their order are written: `to`, `dt`, `amount`,
     * `currency` and `issuer`, `invoiceid`, and `network` last.
     */
    private static function build(
        string $to,
        ?int $tag,
        ?string $amount,
        ?string $currency,
        ?string $issuer,
        ?string $invoiceId,
        string $chain,
        string $network,
    ): string {
        $parameters = ['to' => $to];

        if ($tag !== null) {
            $parameters['dt'] = (string) $tag;
        }

        if ($amount !== null) {
            $parameters['amount'] = $amount;
        }

        if ($currency !== null && $issuer !== null) {
            $parameters['currency'] = $currency;
            $parameters['issuer'] = $issuer;
        }

        if ($invoiceId !== null) {
            $parameters['invoiceid'] = $invoiceId;
        }

        $parameters['network'] = self::NETWORK_KEYS[$chain][$network]
            ?? throw new InvalidArgumentException("Unsupported network '{$network}' on chain '{$chain}'.");

        return self::BASE . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
