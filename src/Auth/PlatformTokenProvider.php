<?php

declare(strict_types=1);

namespace Maxx\Ai\Auth;

final readonly class PlatformTokenProvider implements TokenProvider
{
    public function __construct(#[\SensitiveParameter] private string $accessToken)
    {
        if (!preg_match('/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/', $accessToken)) {
            throw new \InvalidArgumentException('Expected a platform-issued SDK credential.');
        }
    }

    public function token(): string
    {
        return $this->accessToken;
    }

    public function __debugInfo(): array
    {
        return ['credential' => '[REDACTED]'];
    }
}
