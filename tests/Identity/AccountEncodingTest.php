<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Tests\Identity;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Identity\AccountEncoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checksum check behind ChainDescriptor::isValidAccount(). The valid
 * vectors are real accounts — the genesis account, ACCOUNT_ZERO and
 * ACCOUNT_ONE, the issuers in both registries, the SEP-0023 example — so a
 * wrong decoder cannot pass by agreeing with itself.
 */
final class AccountEncodingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validXrplAddresses(): iterable
    {
        yield 'genesis account' => ['rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh'];
        yield 'ACCOUNT_ZERO, leading zero bytes' => ['rrrrrrrrrrrrrrrrrrrrrhoLvTp'];
        yield 'ACCOUNT_ONE' => ['rrrrrrrrrrrrrrrrrrrrBZbvji'];
    }

    /** @return iterable<string, array{string}> */
    public static function validStellarKeys(): iterable
    {
        yield 'SEP-0023 example' => ['GA7QYNF7SOWQ3GLR2BGMZEHXAVIRZA4KVWLTJJFC7MGXUA74P7UJVSGZ'];
        yield 'ChainCatalogTest example' => ['GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA'];
    }

    #[DataProvider('validXrplAddresses')]
    public function testARealXrplAddressIsValid(string $address): void
    {
        self::assertTrue(AccountEncoding::XrplClassicAddress->isValid($address));
    }

    #[DataProvider('validStellarKeys')]
    public function testARealStellarKeyIsValid(string $key): void
    {
        self::assertTrue(AccountEncoding::StellarPublicKey->isValid($key));
    }

    /** Every issuer the registries hold is a real account and must pass. */
    public function testEveryRegistryIssuerIsValid(): void
    {
        $checked = 0;

        foreach (ChainCatalog::chains() as $chain) {
            foreach ($chain->assets as $asset) {
                foreach ($chain->networks as $network) {
                    $issuer = $asset->issuer($network);

                    if ($issuer !== null) {
                        self::assertTrue($chain->accountEncoding?->isValid($issuer), "{$chain->chain} {$asset->code} {$network}: {$issuer}");
                        $checked++;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(8, $checked);
    }

    /**
     * The point of the checksum: every single-character typo — each position
     * replaced by its neighbour in the alphabet — is caught. The pattern alone
     * accepts all of them.
     */
    public function testEverySingleCharacterTypoIsCaught(): void
    {
        $cases = [
            [AccountEncoding::XrplClassicAddress, 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh', 'rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz'],
            [AccountEncoding::StellarPublicKey, 'GA7QYNF7SOWQ3GLR2BGMZEHXAVIRZA4KVWLTJJFC7MGXUA74P7UJVSGZ', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'],
        ];

        foreach ($cases as [$encoding, $valid, $alphabet]) {
            // Position 0 is the chain's prefix, which the pattern guards.
            for ($i = 1; $i < strlen($valid); $i++) {
                $typo = $valid;
                $typo[$i] = $alphabet[(strpos($alphabet, $valid[$i]) + 1) % strlen($alphabet)];

                self::assertFalse($encoding->isValid($typo), "{$encoding->name}: typo at position {$i} ({$typo})");
            }
        }
    }

    /** @return iterable<string, array{AccountEncoding, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'XRPL: empty' => [AccountEncoding::XrplClassicAddress, ''];
        yield 'XRPL: outside the alphabet' => [AccountEncoding::XrplClassicAddress, 'rHb9CJAWyB4rj91VRWn96DkukG4bwdty0h'];
        yield 'XRPL: truncated' => [AccountEncoding::XrplClassicAddress, 'rHb9CJAWyB4rj91VRWn96DkukG4bwdty'];
        yield 'XRPL: X-address' => [AccountEncoding::XrplClassicAddress, 'XVLhHMPHU98es4dbozjVtdWzVrDjtV18pX8yuPT7y4xaEHi'];
        yield 'Stellar: secret seed (S…)' => [AccountEncoding::StellarPublicKey, 'SBU2RRGLXH3E5CQHTD3ODLDF2BWDCYUSSBLLZ5GNW7JXHDIYKXZWHOKR'];
        yield 'Stellar: muxed account (M…)' => [AccountEncoding::StellarPublicKey, 'MCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVMAAAAAAAACP36ET2E'];
        yield 'Stellar: truncated' => [AccountEncoding::StellarPublicKey, 'GA7QYNF7SOWQ3GLR2BGMZEHXAVIRZA4KVWLTJJFC7MGXUA74P7UJVSG'];
        yield 'Stellar: lower case' => [AccountEncoding::StellarPublicKey, strtolower('GA7QYNF7SOWQ3GLR2BGMZEHXAVIRZA4KVWLTJJFC7MGXUA74P7UJVSGZ')];
        yield 'Stellar: an XRPL address' => [AccountEncoding::StellarPublicKey, 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh'];
    }

    #[DataProvider('invalidInputs')]
    public function testMalformedInputIsInvalid(AccountEncoding $encoding, string $input): void
    {
        self::assertFalse($encoding->isValid($input));
    }
}
