<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test-only PSR-3 logger spy — records everything logged instead of writing
 * it anywhere, so tests can assert a warning was emitted.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @return array<int, array{level: mixed, message: string, context: array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }
}
