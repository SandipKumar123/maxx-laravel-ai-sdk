<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class ActionContext
{
    public function __construct(
        public string $externalTenantId,
        public string $actorRef,
        public string $moduleCode,
        public string $serviceCode,
        public string $actionCode,
    ) {
        foreach (get_object_vars($this) as $value) {
            Validate::identifier($value);
        }
    }

    public function toArray(): array
    {
        return [
            'external_tenant_id' => $this->externalTenantId,
            'actor_ref' => $this->actorRef,
            'module_code' => $this->moduleCode,
            'service_code' => $this->serviceCode,
            'action_code' => $this->actionCode,
        ];
    }
}
