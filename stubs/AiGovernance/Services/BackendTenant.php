<?php

namespace Modules\AiGovernance\Services;

use Maxx\Ai\Data\Validate;

final class BackendTenant
{
    public static function id(string $applicationId, string $deploymentId, UsageIdentity $identity): string
    {
        return hash('sha256', json_encode([
            Validate::identifier($applicationId), Validate::identifier($deploymentId), (string) $identity->tenantId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
