<?php

require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';

class Auth
{
    /**
     * Issues a JWT for the given user.
     * $device must be 'web' or 'mobile' — determines token lifetime:
     *   web    -> 1 hour
     *   mobile -> 30 days
     */
    public static function issueToken(int $userId, string $device): array
    {
        $cfg = require __DIR__ . '/../config/config.php';
        $jwtCfg = $cfg['jwt'];

        $device = in_array($device, ['web', 'mobile'], true) ? $device : 'web';
        $ttl = $device === 'mobile' ? $jwtCfg['ttl_mobile_seconds'] : $jwtCfg['ttl_web_seconds'];

        $now = time();
        $jti = bin2hex(random_bytes(16));

        $payload = [
            'iss'    => $jwtCfg['issuer'],
            'sub'    => $userId,
            'device' => $device,
            'iat'    => $now,
            'exp'    => $now + $ttl,
            'jti'    => $jti,
        ];

        $token = JWT::encode($payload, $jwtCfg['secret']);

        return [
            'token'      => $token,
            'token_type' => 'Bearer',
            'device'     => $device,
            'expires_in' => $ttl,
            'expires_at' => date('Y-m-d H:i:s', $now + $ttl),
        ];
    }

    /**
     * Validates the Authorization header on the current request.
     * Returns the decoded payload (contains 'sub' = user id, 'jti', 'device', ...).
     * Sends a 401 JSON error and halts execution if invalid.
     */
    public static function authenticate(): array
    {
        $cfg = require __DIR__ . '/../config/config.php';
        $jwtCfg = $cfg['jwt'];

        $header = self::getAuthorizationHeader();

        if (!$header || stripos($header, 'Bearer ') !== 0) {
            Response::error('Authorization token missing', 401);
        }

        $token = trim(substr($header, 7));

        try {
            $payload = JWT::decode($token, $jwtCfg['secret']);
        } catch (Exception $e) {
            Response::error('Invalid or expired token: ' . $e->getMessage(), 401);
        }

        // Check blacklist (logout invalidation)
        if (!empty($payload['jti']) && self::isBlacklisted($payload['jti'])) {
            Response::error('Token has been revoked. Please login again.', 401);
        }

        return $payload;
    }

    public static function blacklistToken(string $jti, int $userId, int $expiresAtTimestamp): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO token_blacklist (jti, user_id, expires_at) VALUES (:jti, :user_id, :expires_at)
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            ':jti'        => $jti,
            ':user_id'    => $userId,
            ':expires_at' => date('Y-m-d H:i:s', $expiresAtTimestamp),
        ]);
    }

    private static function isBlacklisted(string $jti): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM token_blacklist WHERE jti = :jti LIMIT 1');
        $stmt->execute([':jti' => $jti]);
        return (bool) $stmt->fetch();
    }

    private static function getAuthorizationHeader(): ?string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) {
                    return $value;
                }
            }
        }
        return null;
    }
}
