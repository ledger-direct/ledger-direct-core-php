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
 * outgoing request's URL, so one instance can serve several oracles in the
 * same test. Any request with no matching queued response throws — that's
 * also how tests prove a code path made *no* HTTP call at all (e.g. the
 * USD-peg fast path): queue nothing, and an unexpected call fails loudly
 * instead of silently hitting the real network.
 *
 * @internal
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var array<int, array{needle: string, result: ResponseInterface|Throwable}> */
    private array $queue = [];

    public function queueResponse(string $urlContains, ResponseInterface|Throwable $result): void
    {
        $this->queue[] = ['needle' => $urlContains, 'result' => $result];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();

        foreach ($this->queue as $entry) {
            if (str_contains($uri, $entry['needle'])) {
                if ($entry['result'] instanceof Throwable) {
                    throw $entry['result'];
                }

                return $entry['result'];
            }
        }

        throw new RuntimeException("FakeHttpClient: no response queued for URL '{$uri}'.");
    }
}
