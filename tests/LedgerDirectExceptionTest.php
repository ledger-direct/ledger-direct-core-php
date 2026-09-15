<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagsExhaustedException;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LedgerDirectExceptionTest extends TestCase
{
    /**
     * One catch in a platform's exception handler covers every runtime
     * failure the core raises.
     */
    public function testEveryRuntimeFailureCarriesTheMarker(): void
    {
        self::assertInstanceOf(LedgerDirectException::class, new PriceUnavailableException('x'));
        self::assertInstanceOf(LedgerDirectException::class, new XrplRpcException('x'));
        self::assertInstanceOf(LedgerDirectException::class, new AssetNotAcceptedException('x'));
        self::assertInstanceOf(LedgerDirectException::class, new DestinationTagsExhaustedException('x'));
    }

    /**
     * A value object rejecting its input is a programming error in the
     * caller, not a condition a running shop recovers from.
     */
    public function testAValueObjectsArgumentErrorIsNotOne(): void
    {
        try {
            PaymentIntent::fromArray([]);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotInstanceOf(LedgerDirectException::class, $exception);
        }
    }
}
