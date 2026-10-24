<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Identity;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use PHPUnit\Framework\TestCase;

/**
 * Golden values recorded from DestinationTagService as of 0.7.0, before the
 * permutation moved here. The refactor must not change a single XRPL tag —
 * open orders are waiting on them.
 */
final class SequencePermutationTest extends TestCase
{
    public function testGoldenValuesFromZeroPointSevenAreUnchanged(): void
    {
        $golden = [
            0 => 114729,
            1 => 1836426632,
            2 => 3672738535,
            42 => 4110940623,
            1000 => 2365252337,
            123456 => 2991456729,
            2147483647 => 3547054922,
            4294957295 => 2458760122,
        ];

        foreach ($golden as $sequence => $expected) {
            self::assertSame($expected, SequencePermutation::map($sequence), "sequence {$sequence}");
        }
    }

    public function testTheRangeIsTheUnsigned32BitTagRange(): void
    {
        self::assertSame(10000, SequencePermutation::RANGE_MIN);
        self::assertSame(4294967295, SequencePermutation::RANGE_MAX);
        self::assertSame(4294957296, SequencePermutation::RANGE_SIZE);
        self::assertFalse(SequencePermutation::isExhausted(SequencePermutation::RANGE_SIZE - 1));
        self::assertTrue(SequencePermutation::isExhausted(SequencePermutation::RANGE_SIZE));
        self::assertTrue(SequencePermutation::isExhausted(-1));
    }

    public function testEveryMappedValueIsWithinTheRange(): void
    {
        foreach ([0, 1, 999999, SequencePermutation::RANGE_SIZE - 1] as $sequence) {
            $id = SequencePermutation::map($sequence);
            self::assertGreaterThanOrEqual(SequencePermutation::RANGE_MIN, $id);
            self::assertLessThanOrEqual(SequencePermutation::RANGE_MAX, $id);
        }
    }
}
