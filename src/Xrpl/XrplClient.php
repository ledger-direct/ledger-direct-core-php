<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Xrpl;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin JSON-RPC transport for the two XRPL node calls the core needs
 * (`account_tx`, `tx`). Returns raw, undecoded ledger data — hydrating it
 * into domain objects (e.g. XrplTransaction) is a caller's job (SyncService),
 * not this class's.
 */
final class XrplClient
{
    private const JSON_RPC_URLS = [
        'mainnet' => 'https://xrplcluster.com/',
        'testnet' => 'https://s.altnet.rippletest.net:51234/',
    ];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, marker: string|array|null}
     */
    public function fetchAccountTransactions(
        string $address,
        string $network,
        ?int $afterLedgerIndex = null,
        string|array|null $marker = null,
    ): array {
        $params = [
            'account' => $address,
            'limit' => 200,
            'forward' => true,
        ];

        if ($afterLedgerIndex !== null) {
            $params['ledger_index_min'] = $afterLedgerIndex + 1;
        }

        if ($marker !== null) {
            $params['marker'] = $marker;
        }

        $result = $this->callRpc($network, 'account_tx', $params);

        if (($result['status'] ?? null) === 'error') {
            throw new XrplRpcException(
                "XRPL account_tx on {$network} failed: " . ($result['error'] ?? 'unknown error') . '.'
            );
        }

        return [
            'transactions' => $result['transactions'] ?? [],
            'marker' => $result['marker'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null null when the transaction isn't on the ledger
     *     (yet) — XRPL's `txnNotFound`, an expected outcome when polling for a pending
     *     payment, not a failure.
     */
    public function tx(string $transactionHashOrCtid, string $network): ?array
    {
        $result = $this->callRpc($network, 'tx', [
            'transaction' => $transactionHashOrCtid,
            'binary' => false,
        ]);

        if (($result['status'] ?? null) === 'error') {
            if (($result['error'] ?? null) === 'txnNotFound') {
                return null;
            }

            throw new XrplRpcException(
                "XRPL tx on {$network} failed: " . ($result['error'] ?? 'unknown error') . '.'
            );
        }

        return $result;
    }

    /**
     * Sends the JSON-RPC envelope and returns the decoded `result` object.
     * Throws on transport failure, a non-2xx status, or a malformed body —
     * does NOT interpret `result.status === 'error'`, since what counts as
     * an error (vs. an expected empty/not-found outcome) differs per method.
     *
     * @param array<string, mixed> $paramsObject
     * @return array<string, mixed>
     */
    private function callRpc(string $network, string $method, array $paramsObject): array
    {
        $url = self::urlForNetwork($network);

        try {
            $body = json_encode([
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => [$paramsObject],
                'id' => 1,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new XrplRpcException("Could not encode XRPL {$method} request.", previous: $exception);
        }

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new XrplRpcException(
                "XRPL JSON-RPC transport failure calling '{$method}' on {$network}.",
                previous: $exception,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new XrplRpcException("XRPL JSON-RPC '{$method}' on {$network} returned HTTP {$status}.");
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new XrplRpcException("XRPL JSON-RPC '{$method}' on {$network} returned a malformed response body.");
        }

        if (isset($payload['error'])) {
            throw new XrplRpcException(
                "XRPL JSON-RPC '{$method}' on {$network} failed: {$payload['error']}."
            );
        }

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            throw new XrplRpcException("XRPL JSON-RPC '{$method}' on {$network} returned no result.");
        }

        return $result;
    }

    private static function urlForNetwork(string $network): string
    {
        return self::JSON_RPC_URLS[$network] ?? throw new InvalidArgumentException("Unsupported network '{$network}'.");
    }
}
