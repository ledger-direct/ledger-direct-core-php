<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri as XrplPaymentUri;

/**
 * The SEP-0007 payment request the QR code encodes on Stellar — the
 * sibling of Payment\PaymentUri, same policy: built on the server, the
 * page only renders it, the amount as the page shows it.
 *
 * `web+stellar:pay?destination=<G…>&amount=<amount>&memo=<id>&memo_type=MEMO_ID`,
 * plus `asset_code` and `asset_issuer` for an issued asset, plus
 * `network_passphrase` on testnet — SEP-0007 assumes the public network
 * when it is absent, so a testnet request without it would ask the wallet
 * to pay on mainnet. The memo is
 * always in the request: a payment without it cannot be matched to the
 * order, and typing it was the biggest source of lost payments on XRPL.
 * The destination is the plain G-address with a MEMO_ID rather than a
 * muxed address — the form every SEP-7 wallet supports (E7).
 *
 * Not yet verified by scanning with a wallet; when a platform has done
 * that, note it here as the XRPL sibling does.
 */
final class PaymentUri
{
    public const SCHEME = 'web+stellar:pay';

    /**
     * The testnet's network passphrase as Horizon reports it in its root
     * document (`network_passphrase`; pinned against the recorded one in
     * tests). SEP-0007 wants it verbatim, spaces included. Mainnet needs
     * none: it is the default.
     */
    public const TESTNET_PASSPHRASE = 'Test SDF Network ; September 2015';

    /**
     * @param string $amountDue the amount to send, as the page shows it
     */
    public static function forIntent(
        PaymentIntent $intent,
        string $amountDue,
        string $amountMode = XrplPaymentUri::AMOUNT_MODE,
    ): string {
        $parameters = ['destination' => $intent->destinationAccount];

        if ($amountMode === XrplPaymentUri::AMOUNT_DISPLAYED && $amountDue !== '') {
            $parameters['amount'] = $amountDue;
        }

        if (is_array($intent->amountRequested)) {
            $parameters['asset_code'] = (string) $intent->amountRequested['currency'];
            $parameters['asset_issuer'] = (string) $intent->amountRequested['issuer'];
        }

        $parameters['memo'] = (string) $intent->destinationTag;
        $parameters['memo_type'] = 'MEMO_ID';

        if ($intent->network === 'testnet') {
            $parameters['network_passphrase'] = self::TESTNET_PASSPHRASE;
        }

        return self::SCHEME . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
