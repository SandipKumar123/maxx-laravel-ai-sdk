<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests\ModuleFixtures\AiGovernance;

final class ScopeContext
{
    private ?int $tenant = null;
    private ?string $jurisdiction = null;

    public function establish(int $tenantId, ?int $agencyId, string $region): void
    {
        $this->tenant = $tenantId;
        $this->jurisdiction = $region;
    }

    public function tenantId(): ?int
    {
        return $this->tenant;
    }

    public function region(): ?string
    {
        return $this->jurisdiction;
    }

    public function isEstablished(): bool
    {
        return $this->tenant !== null && $this->jurisdiction !== null;
    }
}
