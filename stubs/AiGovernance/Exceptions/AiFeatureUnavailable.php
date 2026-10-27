<?php

namespace Modules\AiGovernance\Exceptions;

use RuntimeException;

final class AiFeatureUnavailable extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function keyNotActivated(): self
    {
        return new self(
            'AI_KEY_NOT_ACTIVATED',
            'AI Key Not Activated. Please activate and verify your Universal AI key in AI Governance → SDK Connection, then try again.',
        );
    }

    public static function executionUnavailable(): self
    {
        return new self(
            'AI_SDK_EXECUTION_UNAVAILABLE',
            'Universal AI execution requires a verified connection, registered resource, model, region and executor, and explicit local/testing SDK opt-in. Production execution requires a supported SDK release.',
        );
    }

    public function payload(): array
    {
        return [
            'error_code' => $this->errorCode,
            'title' => $this->errorCode === 'AI_KEY_NOT_ACTIVATED'
                ? 'AI Key Not Activated'
                : 'Universal AI Execution Unavailable',
            'message' => $this->getMessage(),
        ];
    }
}
