<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Xrpl;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Refund\DestinationReport;
use Hardcastle\LedgerDirect\Core\Refund\RefundNotice;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundDestinationCheck;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use PHPUnit\Framework\TestCase;

/**
 * On answers recorded on the XRPL testnet on 2026-11-20: one account before and after it set
 * `RequireDest`, its trust lines before and after it opened one for RLUSD; and on the issuer,
 * reserve and not-activated answers the account inspector's tests already use.
 */
final class RefundDestinationCheckTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/refund/';

    private const CUSTOMER = 'rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk';
    private const NOBODY = 'rNALbYJqWYLawAXAuK6wj8kpWcJn2e6uvv';
    private const RLUSD = '524C555344000000000000000000000000000000';
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    public function testAnAccountThatTakesAnythingHasNothingToReport(): void
    {
        $report = $this->check(self::node(), LedgerAmount::native('1.07'));

        self::assertTrue($report->exists);
        self::assertFalse($report->requiresTag);
        self::assertSame([], $report->notices);
        self::assertFalse($report->wouldFail());
    }

    /**
     * The same account after it set RequireDest: the one fact a rule hangs on.
     */
    public function testAnAccountThatRequiresATagSaysSo(): void
    {
        $node = self::node()->answer('account_info ' . self::CUSTOMER, self::recorded('account-info-customer-requires-tag.json'));

        $report = $this->check($node, LedgerAmount::native('1.07'));

        self::assertTrue($report->requiresTag);
        self::assertSame([], $report->notices, 'requiring a tag is not a reason for the ledger to refuse a payment that has one');
    }

    public function testTheAnswerIsNeverFromACache(): void
    {
        $node = self::node();
        $check = new RefundDestinationCheck(new XrplClient($node, new HttpFactory(), new HttpFactory()));

        self::assertFalse($check->check(self::CUSTOMER, 'testnet', LedgerAmount::native('1'))->requiresTag);

        $node->answer('account_info ' . self::CUSTOMER, self::recorded('account-info-customer-requires-tag.json'));

        self::assertTrue($check->check(self::CUSTOMER, 'testnet', LedgerAmount::native('1'))->requiresTag);
        self::assertSame(2, $node->count('account_info'));
    }

    public function testATokenWithoutATrustLineWouldBeRefused(): void
    {
        $report = $this->check(self::node(), self::rlusd('1'));

        self::assertTrue($report->wouldFail());
        self::assertTrue($report->has(RefundNotice::TRUSTLINE_MISSING));
        self::assertSame(['currency' => self::RLUSD, 'issuer' => self::ISSUER], $report->notices[0]->context);
        self::assertTrue($report->notices[0]->blocks);
    }

    public function testATokenWithATrustLineAndRoomArrives(): void
    {
        $node = self::node()->answer('account_lines ' . self::CUSTOMER, self::recorded('account-lines-customer-with-trustline.json'));

        $report = $this->check($node, self::rlusd('21.6'));

        self::assertSame([], $report->notices);
        self::assertSame(self::ISSUER, $node->calls[array_key_last($node->calls)]['params']['peer'] ?? null, 'only the lines to the issuer are asked for');
    }

    /**
     * The recorded line has a limit of 1 000 000 and holds 1.
     */
    public function testATrustLineWithoutRoomForTheAmountWouldRefuseIt(): void
    {
        $node = self::node()->answer('account_lines ' . self::CUSTOMER, self::recorded('account-lines-customer-with-trustline.json'));

        self::assertFalse($this->check($node, self::rlusd('999999'))->wouldFail(), 'exactly the room that is left');

        $report = $this->check($node, self::rlusd('999999.01'));

        self::assertTrue($report->wouldFail());
        self::assertTrue($report->has(RefundNotice::TRUSTLINE_FULL));
        self::assertSame('999999', $report->notices[0]->context['headroom']);
    }

    public function testTheTrustLineIsFoundWhateverCaseTheNodeWritesTheCodeIn(): void
    {
        $lines = self::recorded('account-lines-customer-with-trustline.json');
        $lines['lines'][0]['currency'] = strtolower(self::RLUSD);
        $node = self::node()->answer('account_lines ' . self::CUSTOMER, $lines);

        self::assertSame([], $this->check($node, self::rlusd('1'))->notices);
    }

    public function testALineInAnotherCurrencyOfTheSameIssuerIsNotTheTrustLine(): void
    {
        $lines = self::recorded('account-lines-customer-with-trustline.json');
        $lines['lines'][0]['currency'] = 'USD';
        $node = self::node()->answer('account_lines ' . self::CUSTOMER, $lines);

        self::assertTrue($this->check($node, self::rlusd('1'))->has(RefundNotice::TRUSTLINE_MISSING));
    }

    /**
     * The native asset creates an account if the payment reaches the base reserve (1 XRP on the
     * recorded testnet); a token cannot arrive at an account that does not exist.
     */
    public function testAnAccountThatDoesNotExistTakesTheNativeAssetFromTheReserveOnAndNoToken(): void
    {
        $node = self::node();

        $below = $this->check($node, LedgerAmount::native('0.999999'), self::NOBODY);
        self::assertFalse($below->exists);
        self::assertFalse($below->requiresTag);
        self::assertTrue($below->wouldFail());
        self::assertSame(RefundNotice::DESTINATION_NOT_ACTIVATED, $below->notices[0]->code);
        self::assertSame(['reserve' => '1'], $below->notices[0]->context);

        $reaching = $this->check($node, LedgerAmount::native('1'), self::NOBODY);
        self::assertFalse($reaching->wouldFail(), 'the payment creates the account');
        self::assertTrue($reaching->has(RefundNotice::DESTINATION_NOT_ACTIVATED), 'and the merchant is still told');

        $token = $this->check($node, self::rlusd('5'), self::NOBODY);
        self::assertTrue($token->wouldFail());
        self::assertSame(0, $node->count('account_lines'), 'no account, no lines to ask for');
    }

    public function testDepositAuthorizationIsAdviceNotARefusal(): void
    {
        $info = self::recorded('account-info-customer.json');
        $info['account_data']['Flags'] |= 0x01000000;
        $node = self::node()->answer('account_info ' . self::CUSTOMER, $info);

        $report = $this->check($node, LedgerAmount::native('1'));

        self::assertTrue($report->has(RefundNotice::DESTINATION_DEPOSIT_AUTH));
        self::assertFalse($report->wouldFail());
    }

    public function testWhatTheIssuerDoesToItsToken(): void
    {
        $lines = self::recorded('account-lines-customer-with-trustline.json');
        $issuer = FakeNode::result('rlusd-issuer-account-info.json');

        $frozen = $issuer;
        $frozen['account_data']['Flags'] |= 0x00400000;
        $report = $this->check(
            self::node()->answer('account_lines ' . self::CUSTOMER, $lines)->answer('account_info ' . self::ISSUER, $frozen),
            self::rlusd('1'),
        );
        self::assertTrue($report->has(RefundNotice::ISSUER_FROZEN));
        self::assertTrue($report->wouldFail());

        $requiresAuth = $issuer;
        $requiresAuth['account_data']['Flags'] |= 0x00040000;
        $report = $this->check(
            self::node()->answer('account_lines ' . self::CUSTOMER, $lines)->answer('account_info ' . self::ISSUER, $requiresAuth),
            self::rlusd('1'),
        );
        self::assertTrue($report->has(RefundNotice::TRUSTLINE_NOT_AUTHORIZED));
        self::assertTrue($report->wouldFail());

        $authorized = $lines;
        $authorized['lines'][0]['peer_authorized'] = true;
        $report = $this->check(
            self::node()->answer('account_lines ' . self::CUSTOMER, $authorized)->answer('account_info ' . self::ISSUER, $requiresAuth),
            self::rlusd('1'),
        );
        self::assertSame([], $report->notices);

        $charges = $issuer;
        $charges['account_data']['TransferRate'] = 1005000000;
        $report = $this->check(
            self::node()->answer('account_lines ' . self::CUSTOMER, $lines)->answer('account_info ' . self::ISSUER, $charges),
            self::rlusd('1'),
        );
        self::assertTrue($report->has(RefundNotice::TRANSFER_FEE));
        self::assertSame('1005000000', $report->notices[0]->context['rate']);
        self::assertFalse($report->wouldFail(), 'a transfer fee is advice: the payment may fail, the money is not at risk');
    }

    public function testAFrozenLineIsAdvice(): void
    {
        $lines = self::recorded('account-lines-customer-with-trustline.json');
        $lines['lines'][0]['freeze_peer'] = true;
        $node = self::node()->answer('account_lines ' . self::CUSTOMER, $lines);

        $report = $this->check($node, self::rlusd('1'));

        self::assertTrue($report->has(RefundNotice::TRUSTLINE_FROZEN));
        self::assertFalse($report->wouldFail());
    }

    /**
     * A node that cannot be asked is no answer. In particular it is not "requires no tag".
     */
    public function testANodeThatCannotBeAskedIsAnErrorNotAReport(): void
    {
        $node = self::node()->answer('account_info ' . self::CUSTOMER, ['status' => 'error', 'error' => 'noNetwork']);

        $this->expectException(XrplRpcException::class);

        $this->check($node, LedgerAmount::native('1'));
    }

    private function check(FakeNode $node, LedgerAmount $amount, string $account = self::CUSTOMER): DestinationReport
    {
        return (new RefundDestinationCheck(new XrplClient($node, new HttpFactory(), new HttpFactory())))
            ->check($account, 'testnet', $amount);
    }

    /** The account as recorded before it changed anything: no flags, no trust line. */
    private static function node(): FakeNode
    {
        return (new FakeNode())
            ->answer('account_info ' . self::CUSTOMER, self::recorded('account-info-customer.json'))
            ->answer('account_lines ' . self::CUSTOMER, self::recorded('account-lines-customer-without-trustline.json'))
            ->answer('account_info ' . self::ISSUER, 'rlusd-issuer-account-info.json')
            ->answer('account_info ' . self::NOBODY, 'unactivated-account-info.json')
            ->answer('server_state', 'server-state.json');
    }

    private static function rlusd(string $value): LedgerAmount
    {
        return LedgerAmount::issued(self::RLUSD, self::ISSUER, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function recorded(string $fixture): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $fixture), true, flags: JSON_THROW_ON_ERROR)['result'];
    }
}
