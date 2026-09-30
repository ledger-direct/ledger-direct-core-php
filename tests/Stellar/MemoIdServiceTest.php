<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Stellar;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdsExhaustedException;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use PHPUnit\Framework\TestCase;

final class MemoIdServiceTest extends TestCase
{
    private const ACCOUNT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';

    /**
     * Same sequence, same identifier on both chains: one permutation, one
     * place for the constants.
     */
    public function testAMemoIdIsTheSameNumberAsTheDestinationTagForTheSameSequence(): void
    {
        $stellar = new InMemoryStellarPaymentRepository();
        $stellar->scriptSequences(self::ACCOUNT, [0, 42]);
        $xrpl = new InMemoryXrplTransactionRepository();
        $xrpl->scriptSequences('rAccount', [0, 42]);

        $memo = new MemoIdService($stellar);
        $tag = new DestinationTagService($xrpl);

        self::assertSame(114729, $memo->generateMemoId(self::ACCOUNT));
        self::assertSame($tag->generateDestinationTag('rAccount'), 114729);
        self::assertSame($tag->generateDestinationTag('rAccount'), $memo->generateMemoId(self::ACCOUNT));
    }

    public function testFreshInstallationsDoNotIssueTheSameFirstMemoId(): void
    {
        $first = [];
        for ($i = 0; $i < 5; $i++) {
            $first[] = (new MemoIdService(new InMemoryStellarPaymentRepository()))->generateMemoId(self::ACCOUNT);
        }

        self::assertGreaterThan(1, count(array_unique($first)));
    }

    public function testThrowsWhenTheAccountsSequenceIsExhausted(): void
    {
        $repository = new InMemoryStellarPaymentRepository();
        $repository->scriptSequences(self::ACCOUNT, [SequencePermutation::RANGE_SIZE]);

        $this->expectException(MemoIdsExhaustedException::class);

        (new MemoIdService($repository))->generateMemoId(self::ACCOUNT);
    }
}
