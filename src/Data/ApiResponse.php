<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

use Maxx\Ai\Exceptions\SdkException;

final readonly class ApiResponse
{
    public function __construct(public array $data, public string $correlationId)
    {
    }

    public function string(string $key): string
    {
        if (!isset($this->data[$key]) || !is_string($this->data[$key])) {
            throw new SdkException('Maxx API response is missing an expected string field.');
        }

        return $this->data[$key];
    }

    public function __debugInfo(): array
    {
        return ['correlationId' => $this->correlationId];
    }
}
