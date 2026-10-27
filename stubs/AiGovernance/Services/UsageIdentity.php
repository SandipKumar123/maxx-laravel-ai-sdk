<?php

namespace Modules\AiGovernance\Services;

use App\Models\User;
use App\Support\ScopeContext;

final readonly class UsageIdentity
{
    public function __construct(public int $tenantId, public int $userId, public string $region)
    {
        abort_unless($tenantId > 0 && $userId > 0
            && in_array($region, ['in', 'ae', 'us'], true)
            && $region === strtolower((string) config('app.jurisdiction')), 403, 'Valid regional AI identity is required.');
    }

    public static function current(): self
    {
        $user = auth()->user();
        $scope = app(ScopeContext::class);
        abort_unless($user instanceof User && (int) $user->status === 1
            && $scope->isEstablished() && (int) $user->tenant_id === $scope->tenantId()
            && $scope->region() === strtolower((string) config('app.jurisdiction'))
            && (! $user->profile_jurisdiction_code || strtolower($user->profile_jurisdiction_code) === $scope->region()),
            403, 'Authenticated tenant scope is required for AI usage.');

        return new self((int) $user->tenant_id, (int) $user->id, $scope->region());
    }
}
