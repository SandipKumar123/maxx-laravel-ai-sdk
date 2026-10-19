<?php

declare(strict_types=1);

namespace Maxx\Ai\Billing;

final readonly class BillingEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $accountId,
        public string $invoiceOwnerId,
        public string $logicalActionId,
        public string $currency,
        public string $amount,
        public string $payloadHash,
        public array $claims,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['eventId' => $this->eventId, 'eventType' => $this->eventType];
    }
}
