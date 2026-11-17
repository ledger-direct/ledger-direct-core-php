<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\AccountInspector;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The receiving-account check on the XRP Ledger, against answers recorded on the testnet on
 * 2026-11-17 (tests/Xrpl/fixtures/inspector/). The bare account and the unactivated address are
 * the two states reproduced in the Shopware dev shop that day (Documentation/Mainnet-Testing.md,
 * K3): RLUSD was offered to the bare account and the customer's payment failed with tecPATH_DRY.
 */
final class AccountInspectorTest extends TestCase
{
    /** The Shopware dev shop's account: activated, trust lines to the RLUSD and USDC testnet issuers. */
    private const MERCHANT = 'rBMBL9zS34zGeVUSk9fHAZLQvL24RuC3WY';

    /** Activated from the faucet, no trust lines (K3, case A). */
    private const BARE = 'rw1Zr7RDa8PvjnGF72cBcsSZYvK3WVbnPC';

    /** A generated address nobody funded (K3, case B). */
    private const UNACTIVATED = 'rNALbYJqWYLawAXAuK6wj8kpWcJn2e6uvv';

    private const RLUSD_ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    private const USDC_ISSUER = 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt';

    private const ALL = ['XRP', 'RLUSD', 'USDC'];

    private const LSF_REQUIRE_AUTH = 0x00040000;

    private const LSF_GLOBAL_FREEZE = 0x00400000;

    private const LSF_DEPOSIT_AUTH = 0x01000000;

    public function testAMerchantWithBothTrustLinesIsCleanAndOffersEverything(): void
    {
        $inspector = $this->inspector($this->testnet());

        self::assertTrue($inspector->assess(self::MERCHANT, 'testnet', self::ALL)->isClean());
        foreach (self::ALL as $asset) {
            self::assertTrue($inspector->offers(self::MERCHANT, $asset, 'testnet'), $asset);
        }
    }

    public function testReadsTheTrustLineFromTheAccountsSide(): void
    {
        $line = $this->inspector($this->testnet())->trustline(self::MERCHANT, 'RLUSD', 'testnet');

        self::assertNotNull($line);
        self::assertSame(self::RLUSD_ISSUER, $line->issuer);
        self::assertSame('524C555344000000000000000000000000000000', $line->currency);
        self::assertSame('1.73885', $line->balance);
        self::assertSame('1000000', $line->limit);
        self::assertSame('999998.26115', $line->headroom());
        self::assertFalse($line->frozen);
    }

    public function testAnAccountWithoutTrustLinesIsNotOfferedTokensAndTheMerchantIsWarned(): void
    {
        $inspector = $this->inspector($this->testnet());

        self::assertTrue($inspector->offers(self::BARE, 'XRP', 'testnet'));
        self::assertFalse($inspector->offers(self::BARE, 'RLUSD', 'testnet'), 'tecPATH_DRY on 2026-11-17');
        self::assertFalse($inspector->offers(self::BARE, 'USDC', 'testnet'));

        $assessment = $inspector->assess(self::BARE, 'testnet', self::ALL);
        self::assertSame(['trustline_missing:RLUSD', 'trustline_missing:USDC'], self::codes($assessment));
        foreach ($assessment->findings as $finding) {
            self::assertTrue($finding->blocksOffer);
        }
        self::assertSame(['issuer' => self::USDC_ISSUER], $assessment->forAsset('USDC')[0]->context);
    }

    public function testAnUnactivatedAccountGetsXrpOnlyFromTheBaseReserveUp(): void
    {
        $inspector = $this->inspector($this->testnet());

        self::assertTrue($inspector->offers(self::UNACTIVATED, 'XRP', 'testnet'), 'amount unknown: the first payment may activate it');
        self::assertTrue($inspector->offers(self::UNACTIVATED, 'XRP', 'testnet', '1.13738'), 'K3 case B: paid and settled');
        self::assertTrue($inspector->offers(self::UNACTIVATED, 'XRP', 'testnet', '1'));
        self::assertFalse($inspector->offers(self::UNACTIVATED, 'XRP', 'testnet', '0.99999'), 'tecNO_DST_INSUF_XRP');
        self::assertFalse($inspector->offers(self::UNACTIVATED, 'RLUSD', 'testnet'));
        self::assertSame('1', $inspector->baseReserve('testnet'));
    }

    public function testAnUnactivatedAccountIsAWarningForTheAccountAndBlocksTheTokens(): void
    {
        $assessment = $this->inspector($this->testnet())->assess(self::UNACTIVATED, 'testnet', self::ALL);

        self::assertSame(['account_missing', 'trustline_missing:RLUSD', 'trustline_missing:USDC'], self::codes($assessment));
        self::assertFalse($assessment->findings[0]->blocksOffer, 'XRP stays: a payment at or above the reserve activates the account');
        self::assertSame(['reserve' => '1'], $assessment->findings[0]->context);
    }

    public function testDepositAuthorizationIsAWarningOnly(): void
    {
        $root = FakeNode::result('merchant-account-info.json');
        $root['account_data']['Flags'] = self::LSF_DEPOSIT_AUTH;
        $inspector = $this->inspector($this->testnet()->answer('account_info ' . self::MERCHANT, $root));

        $assessment = $inspector->assess(self::MERCHANT, 'testnet', self::ALL);

        self::assertSame(['deposit_auth'], self::codes($assessment));
        self::assertFalse($assessment->findings[0]->blocksOffer, 'B2B: pre-authorized customers or agents may pay');
        self::assertTrue($inspector->offers(self::MERCHANT, 'XRP', 'testnet'));
        self::assertTrue($inspector->offers(self::MERCHANT, 'RLUSD', 'testnet'));
    }

    public function testAnIssuerThatRequiresAuthorizationNeedsAnAuthorizedLine(): void
    {
        $issuer = FakeNode::result('rlusd-issuer-account-info.json');
        $issuer['account_data']['Flags'] |= self::LSF_REQUIRE_AUTH;
        $node = $this->testnet()->answer('account_info ' . self::RLUSD_ISSUER, $issuer);

        self::assertSame(['trustline_not_authorized:RLUSD'], self::codes($this->inspector($node)->assess(self::MERCHANT, 'testnet', self::ALL)));

        $lines = FakeNode::result('merchant-account-lines.json');
        $lines['lines'][0]['peer_authorized'] = true;
        $node->answer('account_lines ' . self::MERCHANT, $lines);

        self::assertTrue($this->inspector($node)->assess(self::MERCHANT, 'testnet', self::ALL)->isClean());
    }

    public function testAFrozenLineAndAGlobalFreezeBlockTheToken(): void
    {
        $lines = FakeNode::result('merchant-account-lines.json');
        $lines['lines'][1]['freeze_peer'] = true;
        $issuer = FakeNode::result('rlusd-issuer-account-info.json');
        $issuer['account_data']['Flags'] |= self::LSF_GLOBAL_FREEZE;
        $inspector = $this->inspector($this->testnet()
            ->answer('account_lines ' . self::MERCHANT, $lines)
            ->answer('account_info ' . self::RLUSD_ISSUER, $issuer));

        self::assertSame(['issuer_frozen:RLUSD', 'trustline_frozen:USDC'], self::codes($inspector->assess(self::MERCHANT, 'testnet', self::ALL)));
        self::assertFalse($inspector->offers(self::MERCHANT, 'RLUSD', 'testnet'));
        self::assertFalse($inspector->offers(self::MERCHANT, 'USDC', 'testnet'));
    }

    public function testAFullLineBlocksAndTheAmountIsCheckedAgainstTheHeadroom(): void
    {
        $lines = FakeNode::result('merchant-account-lines.json');
        $lines['lines'][0]['limit'] = '1.73885';
        $lines['lines'][1]['limit'] = '5';
        $inspector = $this->inspector($this->testnet()->answer('account_lines ' . self::MERCHANT, $lines));

        $assessment = $inspector->assess(self::MERCHANT, 'testnet', self::ALL);
        self::assertSame(['trustline_full:RLUSD'], self::codes($assessment));
        self::assertSame('0', $assessment->findings[0]->context['headroom']);

        self::assertTrue($inspector->offers(self::MERCHANT, 'USDC', 'testnet', '1.26'), 'headroom 5 - 3.74');
        self::assertFalse($inspector->offers(self::MERCHANT, 'USDC', 'testnet', '1.27'));
    }

    public function testAnUnreachableNodeLeavesTokensOutKeepsXrpAndSaysSo(): void
    {
        $node = (new FakeNode())
            ->answer('account_info', new Response(503, ['Retry-After' => '10']))
            ->answer('server_state', new Response(503));
        $logger = new RecordingLogger();
        $inspector = $this->inspector($node, $logger);

        self::assertTrue($inspector->offers(self::MERCHANT, 'XRP', 'testnet', '0.5'));
        self::assertFalse($inspector->offers(self::MERCHANT, 'RLUSD', 'testnet'));
        self::assertNotEmpty($logger->records());

        $assessment = $inspector->assess(self::MERCHANT, 'testnet', self::ALL);
        self::assertSame(['not_checked'], self::codes($assessment));
        self::assertStringContainsString('HTTP 503', $assessment->findings[0]->context['error']);
    }

    public function testTheCheckoutReadsFromTheCacheAndTheConfigurationScreenAfresh(): void
    {
        $node = $this->testnet();
        $inspector = $this->inspector($node, cache: new InMemoryCache(new FrozenClock()));

        $inspector->offers(self::MERCHANT, 'RLUSD', 'testnet');
        $inspector->offers(self::MERCHANT, 'USDC', 'testnet');
        $inspector->offers(self::MERCHANT, 'XRP', 'testnet');
        self::assertSame(1, $node->count('account_lines'), 'one account_lines for every asset');
        self::assertSame(3, $node->count('account_info'), 'the merchant and the two issuers, once each');

        $inspector->assess(self::MERCHANT, 'testnet', self::ALL);
        self::assertSame(2, $node->count('account_lines'), 'the configuration screen reads afresh');

        $inspector->offers(self::UNACTIVATED, 'XRP', 'testnet', '0.5');
        $inspector->offers(self::UNACTIVATED, 'XRP', 'testnet', '0.5');
        self::assertSame(1, $node->count('server_state'), 'the reserve is cached');
    }

    public function testXahauAsksItsOwnNodeForItsOwnReserve(): void
    {
        $node = (new FakeNode())
            ->answer('account_info ' . self::UNACTIVATED, 'unactivated-account-info.json')
            ->answer('server_state', 'xahau-server-state.json');
        $inspector = new AccountInspector(
            new XrplClient($node, new HttpFactory(), new HttpFactory(), ChainCatalog::XAHAU),
            new RecordingLogger(),
            chain: ChainCatalog::XAHAU,
        );

        self::assertFalse($inspector->offers(self::UNACTIVATED, 'XAH', 'testnet', '0.5'));
        self::assertTrue($inspector->offers(self::UNACTIVATED, 'XAH', 'testnet', '110'));
        self::assertStringStartsWith('https://xahau-test.net/', $node->calls[0]['url']);

        $this->expectException(InvalidArgumentException::class);
        $inspector->offers(self::MERCHANT, 'RLUSD', 'testnet');
    }

    private function testnet(): FakeNode
    {
        return (new FakeNode())
            ->answer('account_info ' . self::MERCHANT, 'merchant-account-info.json')
            ->answer('account_lines ' . self::MERCHANT, 'merchant-account-lines.json')
            ->answer('account_info ' . self::BARE, 'bare-account-info.json')
            ->answer('account_lines ' . self::BARE, 'bare-account-lines.json')
            ->answer('account_info ' . self::UNACTIVATED, 'unactivated-account-info.json')
            ->answer('account_info ' . self::RLUSD_ISSUER, 'rlusd-issuer-account-info.json')
            ->answer('account_info ' . self::USDC_ISSUER, 'usdc-issuer-account-info.json')
            ->answer('server_state', 'server-state.json');
    }

    private function inspector(FakeNode $node, ?RecordingLogger $logger = null, ?InMemoryCache $cache = null): AccountInspector
    {
        return new AccountInspector(new XrplClient($node, new HttpFactory(), new HttpFactory()), $logger ?? new RecordingLogger(), $cache);
    }

    /**
     * @return list<string> `code` or `code:asset`, in order
     */
    private static function codes(Assessment $assessment): array
    {
        return array_map(
            static fn (Finding $finding): string => $finding->code . ($finding->asset !== null ? ':' . $finding->asset : ''),
            $assessment->findings,
        );
    }
}
