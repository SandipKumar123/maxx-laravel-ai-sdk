<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Auth\PlatformTokenProvider;
use Maxx\Ai\Billing\BillingVerifier;
use Maxx\Ai\Exceptions\VerificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testPlatformCredentialIsOpaqueAndRedacted(): void
    {
        $token = 'maxx_sdk_'.str_repeat('a', 43);
        $provider = new PlatformTokenProvider($token);
        self::assertSame($token, $provider->token());
        self::assertStringNotContainsString($token, print_r($provider, true));
        $this->expectException(\InvalidArgumentException::class);
        new PlatformTokenProvider("maxx_sdk_invalid\r\n");
    }

    public function testMachineClaimsExactlyMatchStrictBackendContract(): void
    {
        $keys = Keys::pair();
        $provider = new MachineTokenProvider($keys['private'], 'machine', 'test-issuer', 'hrms-service',
            'customer-1', 'hrms-local', 'hrms-in-local', ['actions:execute', 'billing:ack'], 300);
        $claims = (array) JWT::decode($provider->token(), ['machine' => new Key($keys['public'], 'RS256')]);
        self::assertSame('maxx-m2', $claims['aud']);
        self::assertSame('hrms-service', $claims['sub']);
        self::assertSame(300, $claims['exp'] - $claims['iat']);
        self::assertSame('customer-1', $claims['account_id']);
        self::assertNull($claims['human_actor_ref']);
        self::assertStringNotContainsString('PRIVATE KEY', print_r($provider, true));
        self::assertSame([
            'iss', 'aud', 'sub', 'iat', 'exp', 'account_id', 'application_id', 'deployment_id', 'permissions', 'human_actor_ref',
        ], array_keys($claims));
    }

    public function testBillingKeepsExactDecimalAndAcceptsDurableHistoricalEvents(): void
    {
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        $event = $verifier->event(Keys::event(['iat' => time() - 86400 * 365]));
        self::assertSame('2.500000000001', $event->amount);
        self::assertSame('charge_finalized', $event->eventType);
    }

    #[DataProvider('pythonDecimalStrings')]
    public function testBackendScientificDecimalStringsAreNormalizedExactly(string $wire, string $expected): void
    {
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        self::assertSame($expected, $verifier->event(Keys::event(['amount' => $wire]))->amount);
    }

    public static function pythonDecimalStrings(): array
    {
        return [
            ['0E-12', '0.000000000000'],
            ['1E-12', '0.000000000001'],
            ['1.23E+3', '1230'],
            ['0E+3', '0'],
            ['9E+37', '9'.str_repeat('0', 37)],
        ];
    }

    public function testRotationRetainsOldKeysAndRejectsRemovedKeys(): void
    {
        $verifier = new BillingVerifier([
            'current' => Keys::pair()['public'], 'next' => Keys::pair('next')['public'],
        ], 'customer-1', 'hrms-local');
        self::assertSame('event-1', $verifier->event(Keys::sign(Keys::claims(), 'next'))->eventId);
        self::assertSame('event-1', $verifier->event(Keys::event())->eventId);
        $this->expectException(VerificationException::class);
        (new BillingVerifier(['next' => Keys::pair('next')['public']], 'customer-1', 'hrms-local'))->event(Keys::event());
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidBillingClaimsRejected(array $overrides): void
    {
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        $this->expectException(VerificationException::class);
        $verifier->event(Keys::event($overrides));
    }

    public static function invalidClaims(): array
    {
        return array_map(static fn ($claims) => [$claims], [
            ['account_id' => 'another-customer'], ['aud' => 'crm'], ['iss' => 'forged'],
            ['contract_version' => 'production-v1'], ['invoice_owner_id' => 'crm'],
            ['iat' => time() + 3600], ['iat' => '1'], ['amount' => 1.25],
            ['amount' => '-1'], ['currency' => 'usd'], ['event_type' => 'unknown'],
            ['amount' => '1E-13'], ['amount' => '1E+38'], ['amount' => '1E+999'],
            ['event_id' => '../event'], ['exp' => 1],
            ['event_type' => 'charge_adjustment', 'amount_delta' => '--1', 'adjustment_id' => 'adjustment',
                'reason' => 'approved_credit', 'evidence_hash' => str_repeat('0', 64), 'original_charge' => '1'],
        ]);
    }

    public function testForgedSignatureRejected(): void
    {
        $verifier = new BillingVerifier(['current' => Keys::pair('next')['public']], 'customer-1', 'hrms-local');
        $this->expectException(VerificationException::class);
        $verifier->event(Keys::event());
    }

    public function testAlgorithmConfusionRejected(): void
    {
        $token = JWT::encode(Keys::claims(), str_repeat('secret', 10), 'HS256', 'current');
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        $this->expectException(VerificationException::class);
        $verifier->event($token);
    }

    public function testAdjustmentAndDeliveryMatchBackendDigestOfJsonString(): void
    {
        $signed = Keys::event([
            'event_type' => 'charge_adjustment', 'amount_delta' => '-1.25', 'adjustment_id' => 'adjustment-1',
            'reason' => 'approved_credit', 'evidence_hash' => str_repeat('0', 64), 'original_charge' => '2.5',
        ]);
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        self::assertSame('-1.25', $verifier->webhook($signed, Keys::delivery($signed))->amount);
    }

    #[DataProvider('invalidDeliveries')]
    public function testInvalidDeliveryRejected(array $overrides): void
    {
        $signed = Keys::event();
        $verifier = new BillingVerifier(['current' => Keys::pair()['public']], 'customer-1', 'hrms-local');
        $this->expectException(VerificationException::class);
        $verifier->webhook($signed, Keys::delivery($signed, $overrides));
    }

    public static function invalidDeliveries(): array
    {
        return [
            [['exp' => time() - 1]], [['exp' => time() + 1000]],
            [['payload_hash' => str_repeat('0', 64)]], [['event_id' => 'another-event']], [['aud' => 'crm']],
            [['delivery_id' => null]],
        ];
    }
}
