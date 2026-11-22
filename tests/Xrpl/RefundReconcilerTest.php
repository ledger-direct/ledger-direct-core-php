<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\ReconcileResult;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundChange;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Refund\RefundFinding;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryRefundRepository;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundReconciler;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * The pass over a paying account's transactions. The first tests run it on what an account
 * really did on the testnets on 2026-11-20 (`account-tx-merchant.json`, and Xahau's): it paid a
 * token to an account without the trust line, paid without a tag to an account that requires
 * one, paid with the tag, and paid again in another asset.
 */
final class RefundReconcilerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/refund/';

    private const MERCHANT = 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL';
    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const ELSEWHERE = 'rB2CxqjRjUkbebPZ6MgnXEaoSUFrmfsC9';
    private const RLUSD = '524C555344000000000000000000000000000000';

    private const NOW = 1_800_000_000;
    /** The ledger the refunds in the constructed tests are created in. */
    private const CREATED = 1000;

    private InMemoryRefundRepository $refunds;
    private FakeNode $node;
    private FrozenClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->refunds = new InMemoryRefundRepository();
        $this->node = new FakeNode();
        $this->clock = new FrozenClock(self::NOW);
        $this->logger = new RecordingLogger();
    }

    /* ---------- on what was recorded ---------- */

    /**
     * A refund of 1.07 XRP with tag 4711, requested in ledger 22475011. The account then made
     * four attempts: a token without the trust line (22475012, refused), XRP without the tag
     * (22475016, refused), XRP with the tag (22475018) and a partial token payment (22475022).
     */
    public function testTheRecordedAccountHistoryExecutesTheRefundAndKeepsEveryOtherAttempt(): void
    {
        $refund = $this->open(1, '1.07', tag: 4711, createdLedger: 22475011);
        $this->node
            ->answer('server_state', self::serverState(22475025))
            ->answer('account_tx ' . self::MERCHANT, self::recorded('account-tx-merchant.json'));

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertNull($result->error);
        self::assertTrue($result->changedAnything());

        $stored = $this->refunds->find($refund->id);
        self::assertEquals([$stored], $result->executed);
        self::assertTrue($stored?->isExecuted());
        self::assertSame('F1021650B10ABE15E323D76DD38D6987923D59B5B6284D24A033B98812FDC36B', $stored?->executedHash);
        self::assertSame(22475018, $stored?->executedLedger);
        self::assertSame(848500541 + 946684800, $stored?->executedAt);
        self::assertSame(self::MERCHANT, $stored?->executedAccount);
        self::assertSame('1.07', $stored?->delivered?->value);
        self::assertSame(Refund::MATCHED_BY_KEY, $stored?->matchedBy);
        self::assertSame([], $stored?->deviations);
        self::assertSame(self::NOW, $stored?->closedAt);

        self::assertSame(
            [
                [RefundFinding::FAILED_ATTEMPT, 'tecPATH_DRY', 22475012],
                [RefundFinding::FAILED_ATTEMPT, 'tecDST_TAG_NEEDED', 22475016],
                // Another payment to the same destination after the refund was paid: 1 RLUSD.
                [RefundFinding::AMOUNT_MISMATCH, 'tesSUCCESS', 22475022],
            ],
            array_map(
                static fn (RefundFinding $finding): array => [$finding->code, $finding->result, $finding->ledgerIndex],
                $stored?->findings ?? [],
            ),
        );
        self::assertSame(self::RLUSD, $stored?->findings[2]->delivered?->currency);
        self::assertSame('1', $stored?->findings[2]->delivered?->value, 'what arrived, not the 5 its Amount field says');
        self::assertCount(3, $result->findings);

        self::assertSame(22475011, $this->accountTxCalls()[0]['ledger_index_min'], 'from the ledger the refund was created in');
    }

    /**
     * The same history with the refund requested without a tag: the payment that carried one is
     * not its execution.
     */
    public function testTheRecordedPaymentWithATagDoesNotExecuteARefundWithoutOne(): void
    {
        $refund = $this->open(1, '1.07', tag: null, createdLedger: 22475011);
        $this->node
            ->answer('server_state', self::serverState(22475025))
            ->answer('account_tx ' . self::MERCHANT, self::recorded('account-tx-merchant.json'));

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        $stored = $this->refunds->find($refund->id);
        self::assertSame([], $result->executed);
        self::assertTrue($stored?->isAwaitingExecution());
        self::assertSame(
            [RefundFinding::FAILED_ATTEMPT, RefundFinding::FAILED_ATTEMPT, RefundFinding::TAG_MISMATCH, RefundFinding::TAG_MISMATCH],
            array_column($stored?->findings ?? [], 'code'),
        );
    }

    public function testXahauIsReadThroughItsOwnNodesAndMatchesTheSame(): void
    {
        $merchant = 'rDAzMZQ6bhE4GS4bANJvTyAdqFUntPjS8u';
        $customer = 'raEyUZ43Hx1t1PdDmq25NDozE5p2HRsvBc';
        $refunds = new InMemoryRefundRepository('XAHAU');
        $refund = self::refund(1, '1.07', 4711, 14228198, chain: 'XAHAU', payingAccount: $merchant, destination: $customer);
        $refunds->insert($refund);
        $state = FakeNode::result('xahau-server-state.json');
        $state['state']['validated_ledger']['seq'] = 14228205;
        $this->node
            ->answer('server_state', $state)
            ->answer('account_tx ' . $merchant, json_decode(
                (string) file_get_contents(__DIR__ . '/../Xahau/fixtures/refund-account-tx-merchant.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['result']);

        $result = $this->reconciler($refunds, chain: 'XAHAU')->reconcile($merchant, 'testnet');

        $stored = $refunds->find($refund->id);
        self::assertTrue($result->complete);
        self::assertTrue($stored?->isExecuted());
        self::assertSame('A5680DCB48B7A24C786ECB6AF06B86BAD48E0A46686698F3B1ACBBDA5442D15E', $stored?->executedHash);
        self::assertSame('XAHAU', $stored?->chain);
        self::assertSame([RefundFinding::FAILED_ATTEMPT], array_column($stored?->findings ?? [], 'code'));
        self::assertSame('tecDST_TAG_NEEDED', $stored?->findings[0]->result);
        self::assertSame(['https://xahau-test.net/'], array_values(array_unique(array_column($this->node->calls, 'url'))));
    }

    /* ---------- reading ---------- */

    public function testWithNothingToLookAfterTheNodeIsNotAsked(): void
    {
        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertFalse($result->changedAnything());
        self::assertSame([], $this->node->calls);
    }

    public function testOnlyWhatThePayingAccountSentToARefundsDestinationMatters(): void
    {
        $refund = $this->open(1, '40');
        $this->history(1100, [
            self::entry('incoming', self::MERCHANT, '40000000', 1010, from: self::CUSTOMER),
            self::entry('to somebody else', self::ELSEWHERE, '40000000', 1011),
            ['meta' => ['TransactionResult' => 'tesSUCCESS'], 'tx' => ['Account' => self::MERCHANT, 'TransactionType' => 'TrustSet', 'hash' => self::hash('trust'), 'ledger_index' => 1012], 'validated' => true],
        ]);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertFalse($result->changedAnything());
        self::assertEquals($refund, $this->refunds->find($refund->id));
    }

    /**
     * Running the pass again finds the same transactions and changes nothing: a refund is
     * executed once and keeps one finding per transaction.
     */
    public function testASecondPassOverTheSameLedgersChangesNothing(): void
    {
        $refund = $this->open(1, '40', tag: 4711);
        $this->history(1100, [
            self::entry('no tag', self::CUSTOMER, '40000000', 1010),
            self::entry('paid', self::CUSTOMER, '40000000', 1020, tag: 4711),
        ]);

        $first = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');
        $afterFirst = $this->refunds->find($refund->id);
        $second = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertCount(1, $first->executed);
        self::assertCount(1, $first->findings);
        self::assertFalse($second->changedAnything());
        self::assertTrue($second->complete);
        self::assertEquals($afterFirst, $this->refunds->find($refund->id));
    }

    /**
     * With a cache the next pass starts where the last one ended; without one it starts at the
     * oldest refund again. Either way the outcome is the same.
     */
    public function testTheCursorSavesReadingAndDecidesNothing(): void
    {
        $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->history(1100, []);

        $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');
        self::assertSame(self::CREATED, $this->accountTxCalls()[0]['ledger_index_min']);

        // Nothing new was validated: there is nothing to ask.
        $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');
        self::assertCount(1, $this->accountTxCalls());

        $this->history(1150, [self::entry('paid', self::CUSTOMER, '40000000', 1120)]);
        $result = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertSame(1101, $this->accountTxCalls()[1]['ledger_index_min'], 'from the ledger after the last one read');
        self::assertCount(1, $result->executed);
    }

    /**
     * The public endpoints are pools of nodes. `server_state` is answered by one that is at
     * ledger 1100, `account_tx` by one that has only seen 1097. The pass read to 1097, whatever
     * the first node said — and the next pass asks from 1098, where the payment is.
     */
    public function testTheCursorGoesOnlyAsFarAsTheAnswerItselfWent(): void
    {
        $refund = $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->node
            ->answer('server_state', self::serverState(1100))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => 1097, 'transactions' => []]);

        $first = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');
        self::assertTrue($first->complete);

        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [
            self::entry('paid', self::CUSTOMER, '40000000', 1099),
        ]]);
        $second = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertSame(1098, $this->accountTxCalls()[1]['ledger_index_min']);
        self::assertCount(1, $second->executed);
        self::assertTrue($this->refunds->find($refund->id)?->isExecuted());
    }

    /**
     * An answer that does not say how far it goes moves the cursor only to the ledger before
     * the newest transaction it returned.
     */
    public function testAnAnswerThatDoesNotStateItsRangeMovesTheCursorOnlyToWhatItShowed(): void
    {
        $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->node
            ->answer('server_state', self::serverState(1100))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'transactions' => [
                self::entry('other', self::ELSEWHERE, '1000000', 1040),
            ]]);

        $first = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');
        $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertSame([self::CREATED, 1040], array_column($this->accountTxCalls(), 'ledger_index_min'));
        self::assertFalse($first->complete, 'and such a pass has not shown that it read to the end');
        self::assertSame('The node did not say which ledgers its answer covers.', $first->error);
    }

    /**
     * "Read to the end" is about ledgers. The newest validated ledger is 1100; an answer that
     * ends at 1050 comes from a node that has not seen the last fifty, and "nothing found" in
     * it is not "nothing happened". A few ledgers between two nodes of a pool are the rule and
     * do not count.
     */
    public function testAPassIsCompleteOnlyIfTheAnswerReachesTheNewestValidatedLedger(): void
    {
        $this->open(1, '40');
        $this->node->answer('server_state', self::serverState(1100));

        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => 1050, 'transactions' => []]);
        $behind = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');
        self::assertFalse($behind->complete);
        self::assertSame('The answer covers ledgers up to 1050; the newest validated ledger is 1100.', $behind->error);

        $this->node->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => 1097, 'transactions' => []]);
        self::assertTrue($this->reconciler()->reconcile(self::MERCHANT, 'testnet')->complete);
    }

    /**
     * An answer that says "success" without a transaction list is refused by the client, and
     * the pass is incomplete: it is not an account that did nothing.
     */
    public function testAnAnswerWithoutATransactionListMakesThePassIncomplete(): void
    {
        $refund = $this->open(1, '40');
        $this->node
            ->answer('server_state', self::serverState(1100))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => 1100]);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($result->complete);
        self::assertStringContainsString('no transaction list', (string) $result->error);
        self::assertEquals($refund, $this->refunds->find($refund->id));
    }

    /**
     * A page budget that ends in the middle of one ledger would read the same pages on every
     * pass and never get past it. From the second pass on, the pass reads until that ledger is
     * behind it, whatever its budget.
     */
    public function testAPassGetsPastALedgerWithMoreTransactionsThanItsBudget(): void
    {
        $refund = $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $pages = [
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('a', self::ELSEWHERE, '1000000', 1010)], 'marker' => 'second page of ledger 1010'],
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('b', self::ELSEWHERE, '1000000', 1010)], 'marker' => 'third page'],
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('paid', self::CUSTOMER, '40000000', 1020)]],
        ];

        $first = $this->reconciler(cache: $cache, node: $this->pagedNode($pages, 1100))->reconcile(self::MERCHANT, 'testnet', maxPages: 1);
        self::assertFalse($first->complete);

        $node = $this->pagedNode($pages, 1100);
        $second = $this->reconciler(cache: $cache, node: $node)->reconcile(self::MERCHANT, 'testnet', maxPages: 1);

        self::assertSame(1010, $node->minimums[0], 'it starts with the unfinished ledger again');
        self::assertCount(3, $node->minimums, 'and reads on although its budget is one page');
        self::assertTrue($second->complete);
        self::assertTrue($this->refunds->find($refund->id)?->isExecuted());
    }

    /**
     * Only a test network is ever reset. A mainnet node that far behind is a node that is
     * behind: the refund is not written off as unreachable, and the pass is incomplete.
     */
    public function testOnMainnetANodeFarBehindIsNotTakenForAReset(): void
    {
        $refund = self::refund(1, '40', null, 90000000, network: 'mainnet');
        $this->refunds->insert($refund);
        $this->node->answer('server_state', self::serverState(89000000));

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'mainnet');

        self::assertFalse($result->complete);
        self::assertSame([], $result->unreachable);
    }

    /**
     * The refunds keep changing under the pass and the payment cannot be stored as anybody's
     * execution. The pass says so and leaves its cursor where it was: the next pass meets the
     * transaction again.
     */
    public function testATransactionThatCouldNotBeStoredIsNotPassedOver(): void
    {
        $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1020)]);
        $refusing = new class($this->refunds) implements RefundRepositoryInterface {
            public function __construct(private readonly RefundRepositoryInterface $inner)
            {
            }

            public function update(Refund $refund, int $expectedVersion): bool
            {
                return false;
            }

            public function insert(Refund $refund): bool
            {
                return $this->inner->insert($refund);
            }

            public function find(string $id): ?Refund
            {
                return $this->inner->find($id);
            }

            public function findByReference(string $network, string $reference): ?Refund
            {
                return $this->inner->findByReference($network, $reference);
            }

            public function findBySource(string $network, string $sourceHash): array
            {
                return $this->inner->findBySource($network, $sourceHash);
            }

            public function findByPlatformReference(string $platformReference): array
            {
                return $this->inner->findByPlatformReference($platformReference);
            }

            public function findByDestination(string $network, string $payingAccount, string $destination): array
            {
                return $this->inner->findByDestination($network, $payingAccount, $destination);
            }

            public function findWatched(string $network, string $payingAccount, int $closedSince): array
            {
                return $this->inner->findWatched($network, $payingAccount, $closedSince);
            }

            public function watchedAccounts(string $network, int $closedSince): array
            {
                return $this->inner->watchedAccounts($network, $closedSince);
            }
        };

        $stuck = $this->reconciler($refusing, cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($stuck->complete);
        self::assertStringContainsString(self::hash('paid'), (string) $stuck->error);

        $next = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertSame(self::CREATED, $this->accountTxCalls()[1]['ledger_index_min'], 'the cursor did not move past the transaction');
        self::assertCount(1, $next->executed);
    }

    /**
     * A cursor beyond the node's newest ledger is from before a testnet reset, or from a node
     * that was ahead. It is ignored, never obeyed: obeying it would stop the reading for good.
     */
    public function testACursorAheadOfTheNodeIsIgnored(): void
    {
        $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->history(900000, []);
        $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1050)]);
        $result = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertSame(self::CREATED, $this->accountTxCalls()[1]['ledger_index_min']);
        self::assertCount(1, $result->executed);
    }

    public function testAnotherPageIsFollowedByItsMarker(): void
    {
        $refund = $this->open(1, '40');
        $pages = [
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('other', self::ELSEWHERE, '1000000', 1010)], 'marker' => ['ledger' => 1010, 'seq' => 1]],
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('paid', self::CUSTOMER, '40000000', 1020)]],
        ];
        $node = $this->pagedNode($pages, 1100);

        $result = $this->reconciler(node: $node)->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertTrue($this->refunds->find($refund->id)?->isExecuted());
        self::assertSame([null, ['ledger' => 1010, 'seq' => 1]], $node->markers);
    }

    /* ---------- not reading to the end ---------- */

    /**
     * Whatever keeps the ledger from being read to the end, the result says so, and nothing is
     * concluded from the part that was not read.
     */
    public function testANodeThatDoesNotAnswerLeavesEverythingAsItIsAndSaysSo(): void
    {
        $refund = $this->open(1, '40');
        $this->node
            ->answer('server_state', self::serverState(1100))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'error', 'error' => 'noNetwork']);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($result->complete);
        self::assertStringContainsString('noNetwork', (string) $result->error);
        self::assertFalse($result->changedAnything());
        self::assertEquals($refund, $this->refunds->find($refund->id));
    }

    /**
     * `lgrIdxsInvalid` changes no state here: the payment sync's recovery after a testnet reset
     * (reading again without a lower bound) is not borrowed.
     */
    public function testALedgerRangeTheNodeCannotServeChangesNoState(): void
    {
        $refund = $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $this->node
            ->answer('server_state', self::serverState(1100))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'error', 'error' => 'lgrIdxsInvalid']);

        $result = $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($result->complete);
        self::assertCount(1, $this->accountTxCalls(), 'asked once, not again without a bound');
        self::assertEquals($refund, $this->refunds->find($refund->id));

        $this->history(1100, []);
        $this->reconciler(cache: $cache)->reconcile(self::MERCHANT, 'testnet');
        self::assertSame(self::CREATED, $this->accountTxCalls()[1]['ledger_index_min'], 'no cursor was remembered from the failed pass');
    }

    /**
     * The payment sync skips an entry it cannot read. Here that entry could be the payment that
     * left the account: the pass stops and says so. What it found before stays found.
     */
    public function testAnEntryThatCannotBeReliedOnStopsThePass(): void
    {
        $refund = $this->open(1, '40', tag: 4711);
        $broken = self::entry('broken', self::CUSTOMER, '40000000', 1020, tag: 4711);
        unset($broken['meta']['TransactionResult']);
        $this->history(1100, [
            self::entry('no tag', self::CUSTOMER, '40000000', 1010),
            $broken,
            self::entry('paid', self::CUSTOMER, '40000000', 1030, tag: 4711),
        ]);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($result->complete);
        self::assertStringContainsString('meta.TransactionResult', (string) $result->error);
        self::assertCount(1, $result->findings, 'what came before the entry is kept');
        self::assertSame([], $result->executed, 'what comes after it is unknown');
        self::assertTrue($this->refunds->find($refund->id)?->isAwaitingExecution());
    }

    public function testAPassThatRunsOutOfPagesSaysSoAndTheNextOneGoesOn(): void
    {
        $refund = $this->open(1, '40');
        $cache = new InMemoryCache($this->clock);
        $pages = [
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('a', self::ELSEWHERE, '1000000', 1010), self::entry('b', self::ELSEWHERE, '1000000', 1015)], 'marker' => 'more'],
            ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => [self::entry('paid', self::CUSTOMER, '40000000', 1020)]],
        ];

        $first = $this->reconciler(cache: $cache, node: $this->pagedNode($pages, 1100))->reconcile(self::MERCHANT, 'testnet', maxPages: 1);

        self::assertFalse($first->complete);
        self::assertTrue($this->refunds->find($refund->id)?->isAwaitingExecution());

        $node = $this->pagedNode([$pages[1]], 1100);
        $second = $this->reconciler(cache: $cache, node: $node)->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($second->complete);
        self::assertCount(1, $second->executed);
        self::assertSame(1015, $node->minimums[0], 'the last ledger of the cut-off page is read again: it may have been only half there');
    }

    /* ---------- the reference, and where the money went ---------- */

    public function testAPaymentIsRecognisedByTheReferenceItCarries(): void
    {
        $refund = $this->open(1, '40', tag: 4711);
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1020, tag: 4711, invoiceId: $refund->reference)]);

        $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertSame(Refund::MATCHED_BY_REFERENCE, $this->refunds->find($refund->id)?->matchedBy);
    }

    /**
     * The merchant changed the destination in the wallet before signing. The payment went to an
     * account no refund names, and is found by the reference it still carries.
     */
    public function testAPaymentWithARefundsReferenceToAnotherAccountIsNoticed(): void
    {
        $refund = $this->open(1, '40');
        $this->history(1100, [self::entry('diverted', self::ELSEWHERE, '40000000', 1020, invoiceId: $refund->reference)]);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        $stored = $this->refunds->find($refund->id);
        self::assertTrue($stored?->isAwaitingExecution());
        self::assertSame([RefundFinding::DESTINATION_MISMATCH], array_column($stored?->findings ?? [], 'code'));
        self::assertSame(self::ELSEWHERE, $stored?->findings[0]->destination);
        self::assertCount(1, $result->findings);
    }

    public function testAReferenceOfAnotherAccountsRefundIsNotThisAccountsToAnswer(): void
    {
        $theirs = self::refund(2, '40', null, self::CREATED, payingAccount: self::ELSEWHERE);
        $this->refunds->insert($theirs);
        $ours = $this->open(1, '40');
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1020, invoiceId: $theirs->reference)]);

        $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertEquals($theirs, $this->refunds->find($theirs->id));
        self::assertTrue($this->refunds->find($ours->id)?->isExecuted(), 'the payment is matched by its key instead');
    }

    /* ---------- which refunds are looked after ---------- */

    /**
     * A payment still arrives after a refund was cancelled, a second one after it was executed:
     * for seven days a closed refund is looked after.
     */
    public function testAClosedRefundIsLookedAfterForSevenDays(): void
    {
        $refund = $this->open(1, '40');
        $this->refunds->update($refund->cancelled(self::NOW), $refund->version);
        $this->history(1100, [self::entry('late', self::CUSTOMER, '40000000', 1020)]);

        $this->clock->advance(RefundReconciler::GRACE_SECONDS);
        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');
        self::assertSame([RefundFinding::AFTER_CANCEL], array_map(static fn (RefundChange $change): ?string => $change->finding?->code, $result->findings));

        $other = $this->open(2, '50');
        $this->refunds->update($other->cancelled(self::NOW), $other->version);
        $this->clock->advance(1);
        $calls = count($this->node->calls);
        self::assertFalse($this->reconciler()->reconcile(self::MERCHANT, 'testnet')->changedAnything());
        self::assertCount($calls, $this->node->calls, 'after the grace period the account is no longer read for it');
    }

    /**
     * After a testnet was reset its ledger starts far below the one the refund was created in.
     */
    public function testARefundFromAnotherLedgerEpochIsReportedAndNotLookedFor(): void
    {
        $old = $this->open(1, '40', createdLedger: 22475011);
        $new = $this->open(2, '50', createdLedger: 1000);
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '50000000', 1020)]);

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertEquals([$old], $result->unreachable);
        self::assertTrue($this->refunds->find($new->id)?->isExecuted(), 'the refunds of this epoch are read as usual');
        self::assertSame(1000, $this->accountTxCalls()[0]['ledger_index_min']);
    }

    /**
     * A node a few ledgers behind the one that saw the refund created: nothing to read yet, and
     * not a reason to call the pass complete.
     */
    public function testANodeAFewLedgersBehindTheRefundMakesThePassIncomplete(): void
    {
        $this->open(1, '40', createdLedger: 1103);
        $this->node->answer('server_state', self::serverState(1100));

        $result = $this->reconciler()->reconcile(self::MERCHANT, 'testnet');

        self::assertFalse($result->complete);
        self::assertSame([], $result->unreachable);
        self::assertSame([], $this->accountTxCalls());
    }

    /* ---------- the scheduled task ---------- */

    /**
     * An account with an open refund is read on every run; one that only has refunds in their
     * grace period once an hour; one whose refunds are older, not at all.
     */
    public function testTheScheduledPassReadsOpenAccountsEveryTimeAndGraceOnlyAccountsHourly(): void
    {
        $cache = new InMemoryCache($this->clock);
        $this->open(1, '40');
        $closed = self::refund(2, '50', null, self::CREATED, payingAccount: self::ELSEWHERE);
        $this->refunds->insert($closed);
        $this->refunds->update($closed->cancelled(self::NOW), $closed->version);
        $this->history(1100, []);
        $this->node->answer('account_tx ' . self::ELSEWHERE, ['status' => 'success', 'ledger_index_max' => 1100, 'transactions' => []]);
        $reconciler = $this->reconciler(cache: $cache);

        $accounts = static function (array $results): array {
            $accounts = array_map(static fn (ReconcileResult $result): string => $result->account, $results);
            sort($accounts);

            return $accounts;
        };
        $both = [self::MERCHANT, self::ELSEWHERE];
        sort($both);

        self::assertSame($both, $accounts($reconciler->reconcileWatched('testnet')));

        $this->clock->advance(300);
        self::assertSame([self::MERCHANT], $accounts($reconciler->reconcileWatched('testnet')), 'five minutes later only the account with an open refund');

        $this->clock->advance(RefundReconciler::GRACE_INTERVAL_SECONDS);
        self::assertSame($both, $accounts($reconciler->reconcileWatched('testnet')), 'an hour later both again');

        $this->clock->advance(RefundReconciler::GRACE_SECONDS);
        self::assertSame([self::MERCHANT], $accounts($reconciler->reconcileWatched('testnet')), 'after seven days the closed refund is left alone');

        self::assertSame([], $reconciler->reconcileWatched('mainnet'));
    }

    public function testWithoutACacheTheScheduledPassReadsEveryWatchedAccount(): void
    {
        $closed = $this->open(1, '40');
        $this->refunds->update($closed->cancelled(self::NOW), $closed->version);
        $this->history(1100, []);
        $reconciler = $this->reconciler();

        self::assertCount(1, $reconciler->reconcileWatched('testnet'));
        self::assertCount(1, $reconciler->reconcileWatched('testnet'));
    }

    /**
     * A status endpoint an administrator's browser polls goes through the throttle like the
     * payment status does.
     */
    public function testThePassIsALedgerSyncForTheThrottle(): void
    {
        $refund = $this->open(1, '40');
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1020)]);
        $throttle = new SyncThrottle(new InMemoryCache($this->clock), $this->logger, 30, $this->clock);
        $reconciler = $this->reconciler();

        self::assertTrue($throttle->syncIfDue($reconciler, self::MERCHANT, 'testnet'));
        self::assertFalse($throttle->syncIfDue($reconciler, self::MERCHANT, 'testnet'));
        self::assertTrue($this->refunds->find($refund->id)?->isExecuted());
        self::assertCount(1, $this->accountTxCalls());
    }

    /* ---------- two processes ---------- */

    /**
     * Two passes read the same refund; the other one stores first. This one reads again, finds
     * the transaction to be the refund's execution already, and reports nothing twice.
     */
    public function testAPassThatLosesToAnotherOneStoresNothingTwice(): void
    {
        $refund = $this->open(1, '40');
        $this->history(1100, [self::entry('paid', self::CUSTOMER, '40000000', 1020)]);
        $inner = $this->refunds;
        $rival = new class($inner, fn () => $this->reconciler()->reconcile(self::MERCHANT, 'testnet')) implements RefundRepositoryInterface {
            private bool $raced = false;

            public function __construct(private readonly RefundRepositoryInterface $inner, private readonly \Closure $rival)
            {
            }

            public function update(Refund $refund, int $expectedVersion): bool
            {
                if (!$this->raced) {
                    $this->raced = true;
                    ($this->rival)();
                }

                return $this->inner->update($refund, $expectedVersion);
            }

            public function insert(Refund $refund): bool
            {
                return $this->inner->insert($refund);
            }

            public function find(string $id): ?Refund
            {
                return $this->inner->find($id);
            }

            public function findByReference(string $network, string $reference): ?Refund
            {
                return $this->inner->findByReference($network, $reference);
            }

            public function findBySource(string $network, string $sourceHash): array
            {
                return $this->inner->findBySource($network, $sourceHash);
            }

            public function findByPlatformReference(string $platformReference): array
            {
                return $this->inner->findByPlatformReference($platformReference);
            }

            public function findByDestination(string $network, string $payingAccount, string $destination): array
            {
                return $this->inner->findByDestination($network, $payingAccount, $destination);
            }

            public function findWatched(string $network, string $payingAccount, int $closedSince): array
            {
                return $this->inner->findWatched($network, $payingAccount, $closedSince);
            }

            public function watchedAccounts(string $network, int $closedSince): array
            {
                return $this->inner->watchedAccounts($network, $closedSince);
            }
        };

        $result = $this->reconciler($rival)->reconcile(self::MERCHANT, 'testnet');

        self::assertTrue($result->complete);
        self::assertSame([], $result->executed, 'the other pass executed it');
        self::assertTrue($this->refunds->find($refund->id)?->isExecuted());
        self::assertSame(2, $this->refunds->find($refund->id)?->version);
    }

    /* ---------- helpers ---------- */

    private function reconciler(
        ?RefundRepositoryInterface $refunds = null,
        ?CacheInterface $cache = null,
        string $chain = 'XRPL',
        ?\Psr\Http\Client\ClientInterface $node = null,
    ): RefundReconciler {
        return new RefundReconciler(
            new XrplClient($node ?? $this->node, new HttpFactory(), new HttpFactory(), $chain),
            $refunds ?? $this->refunds,
            $this->logger,
            $this->clock,
            $cache,
            $chain,
        );
    }

    /** A refund awaiting execution, from the merchant to the customer, stored. */
    private function open(int $number, string $amount, ?int $tag = null, int $createdLedger = self::CREATED): Refund
    {
        $refund = self::refund($number, $amount, $tag, $createdLedger);
        self::assertTrue($this->refunds->insert($refund));

        return $refund;
    }

    private static function refund(
        int $number,
        string $amount,
        ?int $tag,
        int $createdLedger,
        string $chain = 'XRPL',
        string $payingAccount = self::MERCHANT,
        string $destination = self::CUSTOMER,
        string $network = 'testnet',
    ): Refund {
        return Refund::request(
            id: str_pad(dechex($number), 32, '0', STR_PAD_LEFT),
            chain: $chain,
            network: $network,
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: 'order-transaction-' . $number,
            sourceHash: self::hash('source-' . $number),
            serial: 1,
            payingAccount: $payingAccount,
            destination: new RefundDestination(
                $destination,
                $tag,
                RefundDestination::ORIGIN_SENDER,
                $tag === null ? RefundDestination::ATTESTATION_OWN_WALLET : null,
            ),
            amount: LedgerAmount::native($amount),
            reference: self::hash('reference-' . $number),
            createdAt: self::NOW - 3600,
            createdLedger: $createdLedger,
            createdBy: 'admin',
        );
    }

    /**
     * The node's newest validated ledger and what the merchant's account did.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function history(int $validatedLedger, array $entries): void
    {
        $this->node
            ->answer('server_state', self::serverState($validatedLedger))
            ->answer('account_tx ' . self::MERCHANT, ['status' => 'success', 'ledger_index_max' => $validatedLedger, 'transactions' => $entries]);
    }

    /**
     * A node that answers account_tx page by page and remembers what it was asked.
     *
     * @param list<array<string, mixed>> $pages
     */
    private function pagedNode(array $pages, int $validatedLedger): object
    {
        return new class($pages, self::serverState($validatedLedger)) implements \Psr\Http\Client\ClientInterface {
            /** @var list<mixed> */
            public array $markers = [];

            /** @var list<int> */
            public array $minimums = [];

            /**
             * @param list<array<string, mixed>> $pages
             * @param array<string, mixed> $state
             */
            public function __construct(private array $pages, private readonly array $state)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if ($body['method'] === 'server_state') {
                    return new \GuzzleHttp\Psr7\Response(200, [], (string) json_encode(['result' => $this->state]));
                }

                $this->markers[] = $body['params'][0]['marker'] ?? null;
                $this->minimums[] = $body['params'][0]['ledger_index_min'];

                return new \GuzzleHttp\Psr7\Response(200, [], (string) json_encode(['result' => array_shift($this->pages)]));
            }
        };
    }

    /**
     * A payment as an entry of `account_tx`.
     *
     * @return array<string, mixed>
     */
    private static function entry(
        string $name,
        string $destination,
        string $drops,
        int $ledgerIndex,
        ?int $tag = null,
        ?string $invoiceId = null,
        string $from = self::MERCHANT,
    ): array {
        $tx = [
            'Account' => $from,
            'Amount' => $drops,
            'Destination' => $destination,
            'Flags' => 0,
            'TransactionType' => 'Payment',
            'date' => 848500511,
            'hash' => self::hash($name),
            'ledger_index' => $ledgerIndex,
        ];
        if ($tag !== null) {
            $tx['DestinationTag'] = $tag;
        }
        if ($invoiceId !== null) {
            $tx['InvoiceID'] = $invoiceId;
        }

        return ['meta' => ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => $drops], 'tx' => $tx, 'validated' => true];
    }

    /**
     * @return list<array<string, mixed>> the parameters of every account_tx call so far
     */
    private function accountTxCalls(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['params'],
            array_filter($this->node->calls, static fn (array $call): bool => $call['method'] === 'account_tx'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverState(int $validatedLedger): array
    {
        $state = FakeNode::result('server-state.json');
        $state['state']['validated_ledger']['seq'] = $validatedLedger;

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function recorded(string $fixture): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR)['result'];
    }

    private static function hash(string $name): string
    {
        return strtoupper(hash('sha256', $name));
    }
}
