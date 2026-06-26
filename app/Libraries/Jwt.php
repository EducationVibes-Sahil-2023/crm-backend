<?php

namespace App\Libraries;

/**
 * Minimal, dependency-free HS256 JSON Web Token helper.
 * Good enough for a single-service API; swap for firebase/php-jwt if you
 * later need more algorithms.
 */
class Jwt
{
    public static function secret(): string
    {
        $secret = getenv('JWT_SECRET');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }
        // Fallback for local dev. Set JWT_SECRET in .env for anything real.
        return 'dev-only-change-me-in-env-32+chars-secret';
    }

    /** Encode a payload into a signed JWT. $ttl is lifetime in seconds. */
    public static function encode(array $claims, int $ttl = 604800): string
    {
        $now    = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $segments = [
            self::b64UrlEncode(json_encode($header)),
            self::b64UrlEncode(json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $signature    = hash_hmac('sha256', $signingInput, self::secret(), true);
        $segments[]   = self::b64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** Verify a token and return its claims, or null if invalid/expired. */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $expected = self::b64UrlEncode(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (! hash_equals($expected, $s)) {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($p), true);
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
