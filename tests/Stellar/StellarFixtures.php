<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use GuzzleHttp\Psr7\Response;
use RuntimeException;

/**
 * The real Horizon and RPC answers recorded by the M0 spike on
 * 2026-09-23 (see the handover, section 12), served back through the
 * FakeHttpClient.
 */
final class StellarFixtures
{
    public const MERCHANT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';
    public const PAYER = 'GDZN7JUZHAJEULYCSOQFON5DC26Z6XOH7AB45LIHLXQCQS5MFUW4QN5C';

    public static function body(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $body = file_get_contents($path);

        if ($body === false) {
            throw new RuntimeException("Missing fixture {$path}.");
        }

        return $body;
    }

    public static function response(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/hal+json'], self::body($name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        return json_decode(self::body($name), true, 512, JSON_THROW_ON_ERROR);
    }
}
