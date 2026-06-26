<?php

namespace App\Libraries;

/**
 * Minimal RFC 6238 TOTP (time-based one-time password) — the scheme used by
 * Google Authenticator / Authy. HMAC-SHA1, 6 digits, 30-second step. No
 * external dependencies (works on stock PHP).
 */
class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Random base32 secret (default 160 bits, the recommended length). */
    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($raw) as $ch) {
            $buffer = ($buffer << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    /** Verify a user-entered code against the secret, allowing ±$window steps for clock drift. */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $counter = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The otpauth:// URI an authenticator app reads (typically via QR). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $binCounter = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = (
            ((ord($part[0]) & 0x7F) << 24) |
            ((ord($part[1]) & 0xFF) << 16) |
            ((ord($part[2]) & 0xFF) << 8) |
            (ord($part[3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $buffer = 0;
        $bits = 0;
        $out = '';
        foreach (str_split($b32) as $ch) {
            $buffer = ($buffer << 5) | strpos(self::B32, $ch);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }
}
