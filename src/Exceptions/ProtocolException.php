<?php

declare(strict_types=1);

namespace Maxx\Ai\Exceptions;

final class ProtocolException extends SdkException
{
    public function __construct(
        public readonly bool $outcomeUnknown,
        public readonly string $correlationId,
    ) {
        parent::__construct('Invalid or oversized Maxx API response. Reconcile mutations before retrying.');
    }
}
