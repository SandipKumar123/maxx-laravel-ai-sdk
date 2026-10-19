<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Firebase\JWT\JWT;

final class Keys
{
    private static array $pairs = [];

    public static function pair(string $name = 'current'): array
    {
        if (!isset(self::$pairs[$name])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || !openssl_pkey_export($key, $private)) {
                throw new \RuntimeException('OpenSSL test key generation failed; configure OPENSSL_CONF on Windows.');
            }
            self::$pairs[$name] = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$pairs[$name];
    }

    public static function claims(array $overrides = []): array
    {
        return array_replace([
            'contract_version' => 'm2-dummy-v1',
            'iss' => 'maxx-m2', 'aud' => 'hrms-local', 'iat' => time(),
            'event_id' => 'event-1', 'event_type' => 'charge_finalized',
            'account_id' => 'customer-1', 'invoice_owner_id' => 'hrms-local',
            'logical_action_id' => 'action-1', 'currency' => 'USD',
            'amount' => '2.500000000001', 'quantity' => '1.25', 'unit' => 'credits',
            'details' => ['pricing_id' => 'price-1'],
            'tenant_id' => 'tenant-1', 'deployment_id' => 'hrms-in-local',
        ], $overrides);
    }

    public static function sign(array $claims, string $kid = 'current'): string
    {
        return JWT::encode($claims, self::pair($kid)['private'], 'RS256', $kid);
    }

    public static function event(array $overrides = []): string
    {
        return self::sign(self::claims($overrides));
    }

    public static function delivery(string $event, array $overrides = []): string
    {
        return self::sign(array_replace([
            'contract_version' => 'm2-dummy-v1', 'iss' => 'maxx-m2', 'aud' => 'hrms-local',
            'iat' => time(), 'exp' => time() + 300, 'delivery_id' => 'delivery-1',
            'event_id' => 'event-1', 'payload_hash' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES)),
        ], $overrides));
    }
}
