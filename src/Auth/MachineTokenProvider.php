<?php

declare(strict_types=1);

namespace Maxx\Ai\Auth;

use Firebase\JWT\JWT;
use Maxx\Ai\Data\Validate;

final class MachineTokenProvider implements TokenProvider
{
    private \OpenSSLAsymmetricKey $key;

    public function __construct(
        #[\SensitiveParameter] string $privateKey,
        private readonly string $keyId,
        private readonly string $issuer,
        private readonly string $subject,
        private readonly string $accountId,
        private readonly string $applicationId,
        private readonly string $deploymentId,
        private readonly array $permissions,
        private readonly int $ttl = 300,
        private readonly ?string $humanActorRef = null,
    ) {
        foreach ([$keyId, $subject, $accountId, $applicationId, $deploymentId] as $value) {
            Validate::identifier($value);
        }
        $allowed = ['actions:execute', 'entitlements:issue', 'receipts:deliver', 'billing:read',
            'billing:ack', 'completion:report', 'human:review'];
        if ($issuer === '' || $ttl < 1 || $ttl > 900 || !array_is_list($permissions)
            || count($permissions) > 10
            || count(array_filter($permissions, static fn ($value) => is_string($value) && in_array($value, $allowed, true))) !== count($permissions)) {
            throw new \InvalidArgumentException('Invalid machine identity configuration.');
        }
        if ($humanActorRef !== null) {
            Validate::identifier($humanActorRef);
        }
        $key = openssl_pkey_get_private($privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new \InvalidArgumentException('Machine signing requires an RSA private key of at least 2048 bits.');
        }
        $this->key = $key;
    }

    public function token(): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer, 'aud' => 'maxx-m2', 'sub' => $this->subject,
            'iat' => $now, 'exp' => $now + $this->ttl,
            'account_id' => $this->accountId, 'application_id' => $this->applicationId,
            'deployment_id' => $this->deploymentId, 'permissions' => $this->permissions,
            'human_actor_ref' => $this->humanActorRef,
        ], $this->key, 'RS256', $this->keyId);
    }

    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId];
    }
}
