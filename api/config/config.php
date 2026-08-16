<?php
/**
 * # Copy this file to your server's environment (or use a .env loader of your choice)
 * Global configuration.
 * In production, load these from environment variables instead of hardcoding.
 */

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'expense_tracker',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'jwt' => [
        // CHANGE THIS to a long random string in production (e.g. 64+ random chars)
        'secret'          => getenv('JWT_SECRET') ?: 'shailesh@135%',
        'algo'             => 'HS256',
        'issuer'           => getenv('JWT_ISSUER') ?: 'expense-tracker-api',
        'ttl_web_seconds'    => 60 * 60,          // 1 hour for web login
        'ttl_mobile_seconds' => 60 * 60 * 24 * 30, // 30 days for mobile login
    ],

    'password_reset' => [
        'ttl_minutes' => 30,
    ],

    // Set to true while developing to see full error traces in JSON responses
    'debug' => getenv('APP_DEBUG') === '1',
];
