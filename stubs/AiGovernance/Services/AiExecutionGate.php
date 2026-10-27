<?php

namespace Modules\AiGovernance\Services;

interface AiExecutionGate
{
    public function assertCanGenerate(?UsageIdentity $identity = null): void;
}
