<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class AuthorizationRequest
{
    public function __construct(
        public ActionContext $context,
        public string $idempotencyKey,
        public string $resourceId,
        public string $modelId,
        public string $region,
        public string $executorSubject,
        public ?string $disJobId = null,
    ) {
        foreach ([$idempotencyKey, $resourceId, $modelId, $region, $executorSubject, $disJobId] as $value) {
            if ($value !== null) {
                Validate::identifier($value);
            }
        }
    }

    public function toArray(): array
    {
        return $this->context->toArray() + [
            'idempotency_key' => $this->idempotencyKey,
            'resource_id' => $this->resourceId,
            'model_id' => $this->modelId,
            'region' => $this->region,
            'executor_subject' => $this->executorSubject,
            'dis_job_id' => $this->disJobId,
        ];
    }
}
