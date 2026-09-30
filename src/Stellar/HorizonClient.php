<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Stellar;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * Horizon as the payment source (INVARIANTS.md, "Stellar", Payment source).
 * Reads `GET /accounts/{id}/payments?join=transactions` and the root
 * document; maps Horizon's field names onto StellarPayment; keeps only
 * payment operations to the account. Endpoints are constants, as with
 * XrplClient — SDF's public instances for both networks.
 */
final class HorizonClient implements PaymentSourceInterface
{
    public const URLS = [
        'mainnet' => 'https://horizon.stellar.org',
        'testnet' => 'https://horizon-testnet.stellar.org',
    ];

    /** Horizon's maximum page size. */
    public const PAGE_LIMIT = 200;

    private const PAYMENT_TYPES = ['payment', 'path_payment_strict_send', 'path_payment_strict_receive'];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function fetchPayments(string $account, string $network, ?string $cursor): array
    {
        $url = self::baseUrl($network) . '/accounts/' . rawurlencode($account)
            . '/payments?join=transactions&order=asc&limit=' . self::PAGE_LIMIT
            . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : '');

        $payload = $this->get($url, $network);
        $records = $payload['_embedded']['records'] ?? null;

        if (!is_array($records)) {
            throw new HorizonException("Horizon payments on {$network} returned no records collection.");
        }

        $payments = [];
        foreach ($records as $record) {
            $payment = self::toPayment($record, $account, $network);
            if ($payment !== null) {
                $payments[] = $payment;
            }
        }

        $last = $records === [] ? null : end($records);

        return [
            'payments' => $payments,
            'cursor' => is_array($last) && isset($last['paging_token']) ? (string) $last['paging_token'] : $cursor,
            'exhausted' => count($records) < self::PAGE_LIMIT,
        ];
    }

    /**
     * The account record — balances (trust lines), flags, sequence — or null
     * when the account does not exist on the network.
     *
     * @return array<string, mixed>|null
     */
    public function account(string $account, string $network): ?array
    {
        try {
            return $this->get(self::baseUrl($network) . '/accounts/' . rawurlencode($account), $network);
        } catch (HorizonException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function latestLedger(string $network): int
    {
        $payload = $this->get(self::baseUrl($network) . '/', $network);

        if (!isset($payload['history_latest_ledger']) || !is_numeric($payload['history_latest_ledger'])) {
            throw new HorizonException("Horizon root on {$network} carries no history_latest_ledger.");
        }

        return (int) $payload['history_latest_ledger'];
    }

    /**
     * A Horizon payment record → StellarPayment, or null when it is not a
     * payment operation to $account (a `create_account`, an
     * `invoke_host_function`, a failed transaction, an outgoing payment).
     *
     * @param array<string, mixed> $record
     */
    public static function toPayment(array $record, string $account, string $network): ?StellarPayment
    {
        if (!in_array($record['type'] ?? null, self::PAYMENT_TYPES, true)) {
            return null;
        }

        if (($record['to'] ?? null) !== $account || ($record['transaction_successful'] ?? true) === false) {
            return null;
        }

        $transaction = is_array($record['transaction'] ?? null) ? $record['transaction'] : [];
        $paymentId = (string) ($record['id'] ?? '');
        $native = ($record['asset_type'] ?? null) === 'native';

        return new StellarPayment(
            network: $network,
            paymentId: $paymentId,
            hash: (string) ($record['transaction_hash'] ?? ''),
            opIndex: BigInteger::of($paymentId)->mod(4096)->toInt(),
            type: (string) $record['type'],
            account: (string) ($record['from'] ?? ''),
            destination: $account,
            memoId: StellarPayment::identifierFrom(
                isset($record['to_muxed_id']) ? (string) $record['to_muxed_id'] : null,
                isset($transaction['memo_type']) ? (string) $transaction['memo_type'] : null,
                isset($transaction['memo']) ? (string) $transaction['memo'] : null,
            ),
            assetCode: $native ? StellarPayment::ASSET_NATIVE : (string) ($record['asset_code'] ?? ''),
            assetIssuer: $native ? null : (string) ($record['asset_issuer'] ?? ''),
            amount: (string) ($record['amount'] ?? '0'),
            createdAt: self::timestamp((string) ($record['created_at'] ?? '')),
            raw: $record,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url, string $network): array
    {
        $request = $this->requestFactory->createRequest('GET', $url)->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new HorizonException("Horizon transport failure on {$network}.", previous: $exception);
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            throw new HorizonException(
                "Horizon on {$network} is rate limiting this client; retry after "
                . ($retryAfter !== '' ? $retryAfter : 'an unknown number of') . ' seconds.',
                status: 429,
                retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new HorizonException("Horizon on {$network} returned HTTP {$status}.", status: $status);
        }

        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            throw new HorizonException("Horizon on {$network} returned a malformed response body.", status: $status);
        }

        return $payload;
    }

    private static function timestamp(string $iso8601): int
    {
        try {
            return (new DateTimeImmutable($iso8601))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function baseUrl(string $network): string
    {
        return self::URLS[$network] ?? throw new InvalidArgumentException("Unsupported network '{$network}'.");
    }
}
