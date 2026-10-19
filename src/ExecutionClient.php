<?php

declare(strict_types=1);

namespace Maxx\Ai;

use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Data\ExecutionRequest;
use Maxx\Ai\Data\Validate;
use Maxx\Ai\Http\Transport;

final readonly class ExecutionClient
{
    public function __construct(private Transport $transport)
    {
    }

    public function execute(ExecutionRequest $request, #[\SensitiveParameter] string $authorization): ApiResponse
    {
        return $this->transport->request('POST', '/v1/executions', $request->toArray(), bearer: $authorization);
    }

    public function status(string $executionId, #[\SensitiveParameter] string $authorization): ApiResponse
    {
        return $this->transport->request(
            'GET', '/v1/executions/'.rawurlencode(Validate::identifier($executionId)), bearer: $authorization,
        );
    }

    public function receipt(string $executionId, #[\SensitiveParameter] string $authorization): ApiResponse
    {
        return $this->transport->request(
            'GET', '/v1/executions/'.rawurlencode(Validate::identifier($executionId)).'/receipt', bearer: $authorization,
        );
    }
}
