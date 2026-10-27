<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Integration\Stellar;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Stellar\AccountInspector;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Stellar\StablecoinRegistry;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Against the live SDF testnet Horizon — opt-in like the oracle integration
 * tests (see README, "Running tests"). Checks that what the M0 fixtures
 * recorded is still what Horizon says, and that the registry's testnet
 * issuers are real accounts.
 */
final class HorizonIntegrationTest extends TestCase
{
    private const MERCHANT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';

    public function testTheSpikeAccountStillHasItsSevenPayments(): void
    {
        $horizon = new HorizonClient(new Client(['timeout' => 15]), new HttpFactory());

        $page = $horizon->fetchPayments(self::MERCHANT, 'testnet', null);

        self::assertGreaterThanOrEqual(7, count($page['payments']), 'a testnet reset would wipe them — then re-run the M0 spike');
        self::assertGreaterThan(0, $horizon->latestLedger('testnet'));
    }

    public function testTheRegistrysTestnetIssuersExistAndAreCirclesFlags(): void
    {
        $inspector = new AccountInspector(new HorizonClient(new Client(['timeout' => 15]), new HttpFactory()), new RecordingLogger());
        $registry = new StablecoinRegistry();

        foreach (StablecoinRegistry::codes() as $code) {
            $issuer = $registry->issuer($code, 'testnet');
            self::assertTrue($inspector->accountExists($issuer, 'testnet'), "{$code} testnet issuer");
            self::assertFalse($inspector->issuerFlags($issuer, 'testnet')->clawbackEnabled, "{$code}: no clawback");
        }
    }
}
