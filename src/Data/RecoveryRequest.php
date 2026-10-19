<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class RecoveryRequest
{
    public function __construct(public string $idempotencyKey, public ?string $disJobId = null)
    {
        Validate::identifier($idempotencyKey);
        if ($disJobId !== null) {
            Validate::identifier($disJobId);
        }
    }

    public function toArray(): array
    {
        return ['idempotency_key' => $this->idempotencyKey, 'dis_job_id' => $this->disJobId];
    }
}
