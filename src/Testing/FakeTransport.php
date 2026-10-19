<?php

declare(strict_types=1);

namespace Maxx\Ai\Testing;

use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Http\Transport;

final class FakeTransport implements Transport
{
    private array $responses = [];
    private array $requests = [];

    public function enqueue(array|\Throwable $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        #[\SensitiveParameter] ?string $bearer = null,
    ): ApiResponse {
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => $query];
        if ($this->responses === []) {
            throw new \LogicException('No fake response queued; real HTTP is never used.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return new ApiResponse($response, 'fake-correlation');
    }

    public function requests(): array
    {
        return $this->requests;
    }

    public function __debugInfo(): array
    {
        return ['requestCount' => count($this->requests), 'queuedResponses' => count($this->responses)];
    }
}
