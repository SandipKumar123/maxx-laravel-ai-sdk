<?php

declare(strict_types=1);

namespace Maxx\Ai\Http;

use Maxx\Ai\Data\ApiResponse;

interface Transport
{
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        #[\SensitiveParameter] ?string $bearer = null,
    ): ApiResponse;
}
