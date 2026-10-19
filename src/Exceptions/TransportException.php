<?php

declare(strict_types=1);

namespace Maxx\Ai\Exceptions;

final class TransportException extends SdkException
{
    public function __construct(
        public readonly bool $outcomeUnknown,
        public readonly string $correlationId,
    ) {
        parent::__construct($outcomeUnknown
            ? 'Maxx request outcome is unknown. Reconcile status before retrying work.'
            : 'Maxx API could not be reached.');
    }
}
