<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Identity;

/**
 * How a chain encodes a receiving account, and the check that goes with it:
 * decode the address and verify its checksum. A pattern alone accepts a
 * mistyped character as long as it is from the right alphabet; quotes then
 * name a destination no wallet will pay to. Both encodings below carry a
 * checksum that catches exactly that.
 *
 * Pure PHP, no extension: base58 and base32 are decoded byte by byte,
 * SHA-256 comes from ext-hash (always present), CRC16 is computed here.
 */
enum AccountEncoding
{
    /**
     * An XRPL classic address (`r…`): base58 in Ripple's alphabet, decoding to
     * 25 bytes — version byte 0x00, the 20-byte account id, and a 4-byte
     * checksum, the first four bytes of SHA-256(SHA-256(version + account id)).
     * https://xrpl.org/docs/concepts/accounts/addresses
     */
    case XrplClassicAddress;

    /**
     * A Stellar account (`G…`, StrKey): base32, decoding to 35 bytes —
     * version byte 6 << 3, the 32-byte Ed25519 public key, and a CRC16-XModem
     * of both, little-endian. SEP-0023.
     */
    case StellarPublicKey;

    private const RIPPLE_ALPHABET = 'rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz';
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const XRPL_ACCOUNT_VERSION = 0x00;
    private const STELLAR_ACCOUNT_VERSION = 6 << 3;

    public function isValid(string $address): bool
    {
        return match ($this) {
            self::XrplClassicAddress => self::isValidXrplClassicAddress($address),
            self::StellarPublicKey => self::isValidStellarPublicKey($address),
        };
    }

    private static function isValidXrplClassicAddress(string $address): bool
    {
        $decoded = self::base58Decode($address, self::RIPPLE_ALPHABET);

        if ($decoded === null || strlen($decoded) !== 25 || ord($decoded[0]) !== self::XRPL_ACCOUNT_VERSION) {
            return false;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($checksum, substr($decoded, 21));
    }

    private static function isValidStellarPublicKey(string $address): bool
    {
        $decoded = self::base32Decode($address);

        if ($decoded === null || strlen($decoded) !== 35 || ord($decoded[0]) !== self::STELLAR_ACCOUNT_VERSION) {
            return false;
        }

        return hash_equals(pack('v', self::crc16Xmodem(substr($decoded, 0, 33))), substr($decoded, 33));
    }

    /**
     * Base58 → bytes, or null for a character outside the alphabet. Each
     * leading "zero digit" (the alphabet's first character) is a leading
     * zero byte, as in every base58check encoding.
     */
    private static function base58Decode(string $input, string $alphabet): ?string
    {
        if ($input === '') {
            return null;
        }

        /** @var list<int> $bytes little-endian base-256 digits */
        $bytes = [];

        foreach (str_split($input) as $character) {
            $carry = strpos($alphabet, $character);

            if ($carry === false) {
                return null;
            }

            foreach ($bytes as $i => $byte) {
                $carry += $byte * 58;
                $bytes[$i] = $carry & 0xFF;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $leadingZeros = strspn($input, $alphabet[0]);

        return str_repeat("\0", $leadingZeros) . implode('', array_map('chr', array_reverse($bytes)));
    }

    /**
     * RFC 4648 base32 without padding → bytes, or null for a character
     * outside the alphabet or leftover bits that are not zero.
     */
    private static function base32Decode(string $input): ?string
    {
        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split($input) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $buffer === 0 ? $output : null;
    }

    private static function crc16Xmodem(string $data): int
    {
        $crc = 0;

        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 8;

            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
