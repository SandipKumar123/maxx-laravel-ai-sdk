<?php

namespace Modules\AiGovernance\Services;

use Modules\AiGovernance\Exceptions\AiFeatureUnavailable;
use Modules\AiGovernance\Models\RegionalSdkConnection;

final class RegionalAiExecutionGate implements AiExecutionGate
{
    public function assertCanGenerate(?UsageIdentity $identity = null): void
    {
        $identity ??= UsageIdentity::current();
        if (! app()->environment(['local', 'testing'])) {
            throw AiFeatureUnavailable::executionUnavailable();
        }
        RegionalDatabase::connection($identity->region);
        $connection = RegionalSdkConnection::query()->find('regional');

        if ($connection === null || ! $connection->enabled || ! $connection->verified_at
            || ! is_string($connection->access_token)
            || ! preg_match('/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/', $connection->access_token)) {
            throw AiFeatureUnavailable::keyNotActivated();
        }

        if (config('maxx-ai.allow_provisional') !== true) {
            throw AiFeatureUnavailable::executionUnavailable();
        }
        foreach (app(ExecutionSettings::class)->current($connection) as $value) {
            if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $value)) {
                throw AiFeatureUnavailable::executionUnavailable();
            }
        }
    }
}
