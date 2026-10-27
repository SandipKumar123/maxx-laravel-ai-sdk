<?php

namespace Modules\AiGovernance\Services;

use App\Models\User;
use App\Support\ScopeContext;

final class GovernanceAccess
{
    private const SDK_ADMIN_ROLES = ['Admin', 'admin', 'Super Admin', 'super-admin', 'HQ Admin', 'Master Admin'];

    public static function allows(?User $user): bool
    {
        $scope = app(ScopeContext::class);
        if (! $user || (int) $user->status !== 1 || ! $scope->isEstablished()
            || (int) $user->tenant_id !== $scope->tenantId()
            || $scope->region() !== strtolower((string) config('app.jurisdiction'))
            || ! in_array($scope->region(), ['in', 'us', 'ae'], true)
            || ($user->profile_jurisdiction_code && strtolower($user->profile_jurisdiction_code) !== $scope->region())) {
            return false;
        }

        return $user->can('ai-governance.index');
    }

    public static function canManageSdk(?User $user): bool
    {
        return self::allows($user)
            && ($user->is_super_admin
                || $user->roles->pluck('name')->intersect(self::SDK_ADMIN_ROLES)->isNotEmpty()
                || $user->can('ai-governance.sdk.manage'));
    }
}
