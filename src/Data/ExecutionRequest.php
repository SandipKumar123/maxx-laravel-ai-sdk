<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class ExecutionRequest
{
    public function __construct(
        public string $logicalActionId,
        public string $attemptId,
        public string $applicationId,
        public string $deploymentId,
        public string $tenantId,
        public string $resourceId,
        public string $modelId,
        public string $region,
        public string $idempotencyKey,
        #[\SensitiveParameter] public string $inputText,
        public int $maxOutputTokens,
    ) {
        foreach ([$logicalActionId, $attemptId, $applicationId, $deploymentId, $tenantId,
            $resourceId, $modelId, $region, $idempotencyKey] as $value) {
            Validate::identifier($value);
        }
        if ($inputText === '' || strlen($inputText) > 1_000_000 || !mb_check_encoding($inputText, 'UTF-8')
            || $maxOutputTokens < 1 || $maxOutputTokens > 100000) {
            throw new \InvalidArgumentException('Invalid execution input or output token ceiling.');
        }
    }

    public function toArray(): array
    {
        return [
            'logical_action_id' => $this->logicalActionId,
            'attempt_id' => $this->attemptId,
            'application_id' => $this->applicationId,
            'deployment_id' => $this->deploymentId,
            'tenant_id' => $this->tenantId,
            'resource_id' => $this->resourceId,
            'model_id' => $this->modelId,
            'region' => $this->region,
            'capability' => 'text',
            'idempotency_key' => $this->idempotencyKey,
            'input_text' => $this->inputText,
            'max_output_tokens' => $this->maxOutputTokens,
        ];
    }

    public function __debugInfo(): array
    {
        return ['logicalActionId' => $this->logicalActionId, 'attemptId' => $this->attemptId];
    }
}
