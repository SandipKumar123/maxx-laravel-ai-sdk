<?php

namespace Modules\AiGovernance\Services;

use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Http\Transport;

final readonly class TrackedTransport implements Transport
{
    public function __construct(private Transport $inner, private UsageRecorder $recorder) {}

    public function request(string $method, string $path, ?array $body = null, array $query = [],
        #[\SensitiveParameter] ?string $bearer = null): ApiResponse
    {
        if ($method === 'POST' && in_array($path, ['/v1/actions/scope-keys', '/v1/actions/authorize'], true)) {
            $identity = UsageIdentity::current();
            abort_unless((string) ($body['external_tenant_id'] ?? '') === (string) $identity->tenantId
                && (string) ($body['actor_ref'] ?? '') === (string) $identity->userId,
                403, 'AI governance identity does not match the authenticated tenant and user.');
        }
        // Status/billing reads are not new AI consumption and must not double-count it.
        if ($method !== 'POST' || $path !== '/v1/executions') {
            return $this->inner->request($method, $path, $body, $query, $bearer);
        }
        $identity = UsageIdentity::current();
        abort_unless(is_string($body['application_id'] ?? null) && is_string($body['deployment_id'] ?? null),
            403, 'AI execution requires its registered application and deployment.');
        abort_unless((string) ($body['tenant_id'] ?? '') === BackendTenant::id($body['application_id'], $body['deployment_id'], $identity),
            403, 'AI execution tenant does not match the authenticated tenant.');

        return $this->recorder->run('maxx-ai', 'execute',
            fn () => $this->inner->request($method, $path, $body, $query, $bearer),
            'maxx-ai', $identity, $body['model_id'] ?? null);
    }
}
