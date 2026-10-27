<?php

namespace Modules\AiGovernance\Services;

use Modules\AiGovernance\Models\RegionalSdkConnection;

final class ExecutionSettings
{
    public function current(?RegionalSdkConnection $connection = null): array
    {
        $connection ??= RegionalSdkConnection::query()->find('regional');

        return [
            'resource_id' => $connection?->resource_id ?? config('hrms-ai.resource_id'),
            'model_id' => $connection?->model_id ?? config('hrms-ai.model_id'),
            'region' => $connection?->execution_region ?? config('hrms-ai.region'),
            'executor_subject' => $connection?->executor_subject ?? config('hrms-ai.executor_subject'),
        ];
    }

    public static function rules(bool $required = true): array
    {
        return array_fill_keys(['resource_id', 'model_id', 'execution_region', 'executor_subject'], [
            $required ? 'required' : 'nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_.:-]{1,255}\z/',
        ]);
    }
}
