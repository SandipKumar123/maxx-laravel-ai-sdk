<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final readonly class CompletionRequest
{
    public function __construct(
        public string $eventId,
        public string $outcome,
        public string $evidenceHash,
        public string $jurisdiction,
        public string $validatorVersion,
        public ?string $humanActorRef = null,
        public ?string $adoptedPolicyReference = null,
    ) {
        foreach ([$eventId, $jurisdiction, $validatorVersion, $humanActorRef, $adoptedPolicyReference] as $value) {
            if ($value !== null) {
                Validate::identifier($value);
            }
        }
        Validate::hash($evidenceHash);
        if (!in_array($outcome, ['accepted', 'rejected', 'partial', 'abandoned'], true)) {
            throw new \InvalidArgumentException('Unsupported completion outcome.');
        }
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'outcome' => $this->outcome,
            'evidence_hash' => $this->evidenceHash,
            'jurisdiction' => $this->jurisdiction,
            'validator_version' => $this->validatorVersion,
            'human_actor_ref' => $this->humanActorRef,
            'adopted_policy_reference' => $this->adoptedPolicyReference,
        ];
    }
}
