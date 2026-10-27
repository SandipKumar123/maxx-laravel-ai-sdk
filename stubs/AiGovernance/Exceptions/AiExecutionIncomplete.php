<?php

namespace Modules\AiGovernance\Exceptions;

final class AiExecutionIncomplete extends \RuntimeException
{
    public readonly bool $outcomeUnknown;

    public function __construct(public readonly string $requestId, bool $outcomeUnknown = true)
    {
        $this->outcomeUnknown = $outcomeUnknown;
        parent::__construct('Universal AI execution did not complete. Reference: '.$requestId.'. Check its status before starting another request.');
    }
}
