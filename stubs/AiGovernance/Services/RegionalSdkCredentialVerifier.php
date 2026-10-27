<?php

namespace Modules\AiGovernance\Services;

use GuzzleHttp\ClientInterface;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Http\GuzzleTransport;
use Maxx\Ai\Http\Settings;

final readonly class RegionalSdkCredentialVerifier
{
    public function __construct(private ClientInterface $http) {}

    public function scopeKeys(string $baseUrl, string $token, ActionContext $context): ApiResponse
    {
        $settings = new Settings(
            $baseUrl,
            app()->environment(),
            (bool) config('maxx-ai.allow_provisional'),
            (float) config('maxx-ai.timeout'),
            (float) config('maxx-ai.connect_timeout'),
        );
        $tokens = new class($token) implements TokenProvider {
            public function __construct(private readonly string $token) {}

            public function token(): string
            {
                return $this->token;
            }
        };

        return (new GovernanceClient(new GuzzleTransport($this->http, $settings, $tokens)))->scopeKeys($context);
    }
}
