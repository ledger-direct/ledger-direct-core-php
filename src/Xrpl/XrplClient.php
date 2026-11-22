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
 * Thin JSON-RPC transport for the XRPL node calls the core needs
 * (`account_tx`, `tx`, `account_info`, `account_lines`, `server_state`).
 * Returns raw, undecoded ledger data — hydrating it into domain objects
 * (e.g. XrplTransaction) is a caller's job (SyncService), not this class's.
 *
 * Also the transport for Xahau, which speaks the same protocol on its own
 * nodes: the chain picks the endpoint, nothing else changes.
 */
final class XrplClient
{
    /**
     * rippled's error when the requested ledger range isn't one it can
     * serve — in practice, a stored cursor pointing past the network's
     * current ledger. The testnet is periodically reset, which rewinds its
     * ledger index and strands every cursor from the previous epoch.
     */
    public const ERROR_LEDGER_INDEXES_INVALID = 'lgrIdxsInvalid';

    private const MAX_ACCOUNT_LINES_PAGES = 10;

    /**
     * Fixed public endpoints per chain and network (INVARIANTS.md, "Xahau",
     * Endpoints). Xahau's are run by its foundation; a merchant cannot
     * change them.
     */
    private const JSON_RPC_URLS = [
        'XRPL' => [
            'mainnet' => 'https://xrplcluster.com/',
            'testnet' => 'https://s.altnet.rippletest.net:51234/',
        ],
        'XAHAU' => [
            'mainnet' => 'https://xahau.network/',
            'testnet' => 'https://xahau-test.net/',
        ],
    ];

    /**
     * @param string $chain 'XRPL' or 'XAHAU' — which nodes to ask
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $chain = 'XRPL',
    ) {
        if (!isset(self::JSON_RPC_URLS[$chain])) {
            throw new InvalidArgumentException("Unsupported chain '{$chain}'.");
        }
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
            $error = isset($result['error']) ? (string) $result['error'] : null;

            throw new XrplRpcException(
                "{$this->chain} account_tx on {$network} failed: " . ($error ?? 'unknown error') . '.',
                error: $error,
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
        // A CTID has its own parameter; in `transaction` a node reads it as a malformed
        // hash and answers notImpl — rippled and Xahau alike.
        $parameter = XrplTransaction::isValidCtid($transactionHashOrCtid) ? 'ctid' : 'transaction';

        $result = $this->callRpc($network, 'tx', [
            $parameter => $transactionHashOrCtid,
            'binary' => false,
        ]);

        if (($result['status'] ?? null) === 'error') {
            if (($result['error'] ?? null) === 'txnNotFound') {
                return null;
            }

            $error = isset($result['error']) ? (string) $result['error'] : null;

            throw new XrplRpcException(
                "{$this->chain} tx on {$network} failed: " . ($error ?? 'unknown error') . '.',
                error: $error,
            );
        }

        return $result;
    }

    /**
     * The account's root entry on the validated ledger, or null when the account does not
     * exist (`actNotFound`): an address nobody has funded with the base reserve yet.
     *
     * @return array<string, mixed>|null the `account_data` object
     */
    public function accountInfo(string $address, string $network): ?array
    {
        $result = $this->callRpc($network, 'account_info', [
            'account' => $address,
            'ledger_index' => 'validated',
        ]);

        if (($result['status'] ?? null) === 'error') {
            if (($result['error'] ?? null) === 'actNotFound') {
                return null;
            }

            throw $this->resultError('account_info', $network, $result);
        }

        $data = $result['account_data'] ?? null;
        if (!is_array($data)) {
            throw new XrplRpcException("{$this->chain} account_info on {$network} returned no account_data.");
        }

        return $data;
    }

    /**
     * The account's trust lines on the validated ledger, from the account's side (`balance`
     * positive: the account holds the token; `freeze_peer`: the issuer froze the line;
     * `peer_authorized`: the issuer authorized it). With $peer, only the lines to that account.
     * Empty for an account that does not exist.
     *
     * @return list<array<string, mixed>>
     */
    public function accountLines(string $address, string $network, ?string $peer = null): array
    {
        $params = ['account' => $address, 'ledger_index' => 'validated', 'limit' => 400];
        if ($peer !== null) {
            $params['peer'] = $peer;
        }

        $lines = [];
        // A receiving account has a handful of lines; the bound only guards against a node that
        // keeps answering with a marker.
        for ($page = 0; $page < self::MAX_ACCOUNT_LINES_PAGES; $page++) {
            $result = $this->callRpc($network, 'account_lines', $params);

            if (($result['status'] ?? null) === 'error') {
                if (($result['error'] ?? null) === 'actNotFound') {
                    return [];
                }

                throw $this->resultError('account_lines', $network, $result);
            }

            foreach ($result['lines'] ?? [] as $line) {
                if (is_array($line)) {
                    $lines[] = $line;
                }
            }

            $marker = $result['marker'] ?? null;
            if ($marker === null) {
                return $lines;
            }
            $params['marker'] = $marker;
            // The following pages must come from the same ledger as the first, not from whichever
            // ledger is validated by then (https://xrpl.org/docs/references/http-websocket-apis/api-conventions/markers-and-pagination).
            if (isset($result['ledger_index']) && is_int($result['ledger_index'])) {
                $params['ledger_index'] = $result['ledger_index'];
            }
        }

        throw new XrplRpcException("{$this->chain} account_lines on {$network} for {$address} did not end after " . self::MAX_ACCOUNT_LINES_PAGES . ' pages.');
    }

    /**
     * The network's reserves on the validated ledger, in drops: what an account must hold to
     * exist (`base`) and what each object it owns adds (`increment`). The validators vote on them
     * and they differ between chains, so they come from the node, not from a constant.
     *
     * @return array{base: string, increment: string}
     */
    public function reserves(string $network): array
    {
        $ledger = $this->validatedLedger($network);
        $base = self::drops($ledger['reserve_base'] ?? null);
        $increment = self::drops($ledger['reserve_inc'] ?? null);
        if ($base === null || $increment === null) {
            throw new XrplRpcException("{$this->chain} server_state on {$network} carries no validated ledger reserves.");
        }

        return ['base' => $base, 'increment' => $increment];
    }

    /**
     * The index of the newest ledger the node has validated. A mark taken from the ledger itself
     * rather than from a clock: whatever happens on an account after this call is in a ledger
     * with a higher index. `account_tx` serves `ledger_index_min` up to exactly this index and
     * answers `lgrIdxsInvalid` for the next one (recorded on both testnets, 2026-11-20), so a
     * caller that reads "from this ledger on" passes this index minus one to
     * fetchAccountTransactions(), which asks for the ledger after the one it is given.
     */
    public function validatedLedgerIndex(string $network): int
    {
        $index = $this->validatedLedger($network)['seq'] ?? null;
        if (!is_int($index) || $index < 1) {
            throw new XrplRpcException("{$this->chain} server_state on {$network} carries no validated ledger index.");
        }

        return $index;
    }

    /**
     * `state.validated_ledger` of `server_state`; empty when the node reports none (it is still
     * syncing), which each caller turns into its own error.
     *
     * @return array<string, mixed>
     */
    private function validatedLedger(string $network): array
    {
        $result = $this->callRpc($network, 'server_state', []);

        if (($result['status'] ?? null) === 'error') {
            throw $this->resultError('server_state', $network, $result);
        }

        $ledger = $result['state']['validated_ledger'] ?? null;

        return is_array($ledger) ? $ledger : [];
    }

    /** A drops amount as a digit string, or null when the value is not one. */
    private static function drops(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        return is_string($value) && ctype_digit($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function resultError(string $method, string $network, array $result): XrplRpcException
    {
        $error = isset($result['error']) ? (string) $result['error'] : null;

        return new XrplRpcException(
            "{$this->chain} {$method} on {$network} failed: " . ($error ?? 'unknown error') . '.',
            error: $error,
        );
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
        $url = $this->urlForNetwork($network);

        try {
            $body = json_encode([
                'jsonrpc' => '2.0',
                'method' => $method,
                // An empty PHP array would go out as [] — the node expects an object.
                'params' => [$paramsObject === [] ? new \stdClass() : $paramsObject],
                'id' => 1,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new XrplRpcException("Could not encode {$this->chain} {$method} request.", previous: $exception);
        }

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new XrplRpcException(
                "{$this->chain} JSON-RPC transport failure calling '{$method}' on {$network}.",
                previous: $exception,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // A rate-limited node (429, 503) says when to come back. Nothing waits here: a
            // sync runs inside a shop request, and the next one after the throttle retries.
            $retryAfter = $response->getHeaderLine('Retry-After');

            throw new XrplRpcException(
                "{$this->chain} JSON-RPC '{$method}' on {$network} returned HTTP {$status}"
                    . ($retryAfter !== '' ? ", retry after {$retryAfter}" : '') . '.'
            );
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new XrplRpcException("{$this->chain} JSON-RPC '{$method}' on {$network} returned a malformed response body.");
        }

        if (isset($payload['error'])) {
            throw new XrplRpcException(
                "{$this->chain} JSON-RPC '{$method}' on {$network} failed: {$payload['error']}."
            );
        }

        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            throw new XrplRpcException("{$this->chain} JSON-RPC '{$method}' on {$network} returned no result.");
        }

        return $result;
    }

    private function urlForNetwork(string $network): string
    {
        return self::JSON_RPC_URLS[$this->chain][$network] ?? throw new InvalidArgumentException("Unsupported network '{$network}'.");
    }
}
