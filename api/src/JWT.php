<?php

/**
 * Minimal HS256 JWT implementation (no external dependencies).
 * Supports encode/decode + expiry + signature verification.
 */
class JWT
{
    public static function encode(array $payload, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];

        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Decodes and verifies a JWT.
     * Throws Exception on invalid signature, malformed token, or expiry.
     */
    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception('Malformed token');
        }

        [$headB64, $payloadB64, $sigB64] = $parts;

        $signingInput = $headB64 . '.' . $payloadB64;
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));

        if (!hash_equals($expectedSig, $sigB64)) {
            throw new Exception('Invalid token signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid token payload');
        }

        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new Exception('Token has expired');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
