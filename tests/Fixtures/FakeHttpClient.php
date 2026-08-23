<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Test-only fake PSR-18 client. Responses are matched by a substring of the
 * outgoing request's URL and consumed FIFO — queueing the same needle twice
 * serves the first response on the first matching call and the second on
 * the next one, so a single instance can script a multi-call sequence (e.g.
 * paginated responses). Any request with no matching queued response left
 * throws — that's also how tests prove a code path made *no* HTTP call at
 * all (e.g. the USD-peg fast path): queue nothing, and an unexpected call
 * fails loudly instead of silently hitting the real network.
 *
 * @internal
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var array<int, array{needle: string, result: ResponseInterface|Throwable}> */
    private array $queue = [];

    /** @var list<RequestInterface> every request received, in order — for asserting what was sent */
    private array $sentRequests = [];

    public function queueResponse(string $urlContains, ResponseInterface|Throwable $result): void
    {
        $this->queue[] = ['needle' => $urlContains, 'result' => $result];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sentRequests[] = $request;
        $uri = (string) $request->getUri();

        foreach ($this->queue as $index => $entry) {
            if (str_contains($uri, $entry['needle'])) {
                unset($this->queue[$index]);

                if ($entry['result'] instanceof Throwable) {
                    throw $entry['result'];
                }

                return $entry['result'];
            }
        }

        throw new RuntimeException("FakeHttpClient: no response queued for URL '{$uri}'.");
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->sentRequests[count($this->sentRequests) - 1] ?? null;
    }

    /** @return list<RequestInterface> every request received, in order */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }
}
