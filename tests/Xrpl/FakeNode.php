<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A JSON-RPC node for tests: answers by method and account (`account_info rAbc…`,
 * `server_state`) from the fixtures recorded on the testnet, or from an answer a test
 * set itself; counts the calls. FakeHttpClient matches on the URL only, and every XRPL
 * call goes to the same URL.
 */
final class FakeNode implements ClientInterface
{
    public const FIXTURES = __DIR__ . '/fixtures/inspector/';

    /** @var array<string, ResponseInterface|Throwable> */
    private array $answers = [];

    /** @var list<array{url: string, method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** Answers `$method $account` (or `$method` alone) with a fixture file, a result object, a raw response or an exception. */
    public function answer(string $call, string|array|ResponseInterface|Throwable $fixtureOrResult): self
    {
        $this->answers[$call] = match (true) {
            $fixtureOrResult instanceof Throwable, $fixtureOrResult instanceof ResponseInterface => $fixtureOrResult,
            is_array($fixtureOrResult) => new Response(200, [], json_encode(['result' => $fixtureOrResult], JSON_THROW_ON_ERROR)),
            default => new Response(200, [], (string) file_get_contents(self::FIXTURES . $fixtureOrResult)),
        };

        return $this;
    }

    /**
     * A recorded fixture's `result`, to change a field and answer with it.
     *
     * @return array<string, mixed>
     */
    public static function result(string $fixture): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR)['result'];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $method = (string) $body['method'];
        $params = (array) ($body['params'][0] ?? []);
        $this->calls[] = ['url' => (string) $request->getUri(), 'method' => $method, 'params' => $params];

        $answer = $this->answers[trim($method . ' ' . ($params['account'] ?? ''))] ?? $this->answers[$method] ?? null;
        if ($answer === null) {
            throw new RuntimeException("FakeNode: no answer for {$method} " . ($params['account'] ?? ''));
        }
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        $answer->getBody()->rewind();

        return $answer;
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }
}
