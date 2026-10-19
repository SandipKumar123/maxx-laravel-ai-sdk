<?php

declare(strict_types=1);

namespace Maxx\Ai\Http;

final readonly class Settings
{
    public function __construct(
        public string $baseUrl,
        public string $environment = 'production',
        public bool $allowProvisional = false,
        public float $timeout = 15,
        public float $connectTimeout = 5,
    ) {
        if (!$allowProvisional || !in_array($environment, ['local', 'testing'], true)) {
            throw new \InvalidArgumentException('m2-dummy-v1 is only supported with explicit local/testing opt-in.');
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
            || ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))
            || !is_finite($timeout) || !is_finite($connectTimeout)
            || $timeout <= 0 || $timeout > 120 || $connectTimeout <= 0 || $connectTimeout > $timeout) {
            throw new \InvalidArgumentException('Use an HTTPS origin (loopback HTTP only) and bounded positive timeouts.');
        }
    }
}
