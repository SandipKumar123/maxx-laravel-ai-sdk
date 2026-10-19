<?php

declare(strict_types=1);

namespace Maxx\Ai\Exceptions;

final class ApiException extends SdkException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $correlationId,
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct("Maxx API rejected request: {$errorCode} (HTTP {$status}).");
    }
}
