<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class EntitlementRequest
{
    public function __construct(
        public string $eventId,
        public string $amount,
        public bool $confirmed,
        public string $sourceReference,
    ) {
        Validate::identifier($eventId);
        Validate::decimal($amount);
        Validate::identifier($sourceReference);
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'amount' => $this->amount,
            'confirmed' => $this->confirmed,
            'source_reference' => $this->sourceReference,
        ];
    }
}
