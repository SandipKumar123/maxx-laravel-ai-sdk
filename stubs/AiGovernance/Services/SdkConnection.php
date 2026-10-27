<?php

namespace Modules\AiGovernance\Services;

use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\ProtocolException;
use Maxx\Ai\Exceptions\SdkException;
use Maxx\Ai\Exceptions\TransportException;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Http\Transport;
use Modules\AiGovernance\Models\RegionalSdkConnection;
use Throwable;

final class SdkConnection
{
    public function status(): array
    {
        $identity = UsageIdentity::current();
        RegionalDatabase::connection($identity->region);
        $connection = RegionalSdkConnection::query()->find('regional');

        if ($connection !== null && ! $connection->enabled) {
            return $this->result('disabled', 'The Maxx AI connection has been disabled by your administrator.');
        }
        if ($connection === null && config('maxx-ai.enabled') !== true) {
            return $this->result('disabled', 'The Maxx AI connection has not been configured by your administrator.');
        }
        if (! app()->environment(['local', 'testing']) || config('maxx-ai.allow_provisional') !== true) {
            return $this->result('blocked', 'This SDK release requires an approved local or testing environment.');
        }
        try {
            new Settings((string) ($connection?->base_url ?? config('maxx-ai.base_url')), app()->environment(), true,
                (float) config('maxx-ai.timeout'), (float) config('maxx-ai.connect_timeout'));
            if ($connection !== null) {
                if (! is_string($connection->access_token)
                    || ! preg_match('/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/', $connection->access_token)) {
                    return $this->result('configuration_required', 'Enter a valid Universal AI Client Access credential.');
                }

                if ($connection->verified_at) {
                    return $this->result('verified', 'Saved connection was verified at '.$connection->verified_at->format('Y-m-d H:i:s').' UTC. Verify again to confirm current backend access.', true);
                }
                return $this->result('ready', 'Connection settings are present. Verify access with Universal AI.', true);
            }
            foreach (['private_key_path', 'kid', 'issuer', 'subject', 'account_id', 'application_id', 'deployment_id'] as $field) {
                if (! is_string(config('maxx-ai.machine.'.$field)) || trim(config('maxx-ai.machine.'.$field)) === '') {
                    return $this->result('configuration_required', 'Your administrator must complete the regional AI connection setup.');
                }
            }
            $path = config('maxx-ai.machine.private_key_path');
            $real = str_contains($path, '://') ? false : realpath($path);
            $public = realpath(public_path());
            if (! $real || ! is_file($real) || ! is_readable($real)
                || ($public && str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower(str_replace('\\', '/', $public)).'/'))
                || ! in_array('actions:execute', (array) config('maxx-ai.machine.permissions'), true)) {
                return $this->result('configuration_required', 'The regional AI connection needs a protected machine identity and access permission.');
            }
        } catch (Throwable) {
            return $this->result('configuration_required', 'The regional AI connection configuration is invalid.');
        }

        return $this->result('ready', 'Configuration is present. Check the connection to verify backend access.', true);
    }

    public function check(): array
    {
        $identity = UsageIdentity::current();
        RegionalDatabase::connection($identity->region);
        $status = $this->status();
        if (! $status['can_check']) {
            return $status;
        }

        try {
            app(TokenProvider::class);
            $response = app(GovernanceClient::class)->scopeKeys(new ActionContext(
                (string) $identity->tenantId, (string) $identity->userId, 'ai-governance', 'connection', 'check',
            ));

            return $this->connectedResponse($response);
        } catch (ApiException $error) {
            return $this->apiFailure($error);
        } catch (TransportException) {
            return $this->result('unavailable', 'The AI backend could not be reached. Try checking again later.', true);
        } catch (ProtocolException) {
            return $this->result('invalid_response', 'The AI backend returned an unexpected connection response.', true);
        } catch (SdkException) {
            return $this->result('invalid_response', 'The AI backend returned an unexpected connection response.', true);
        } catch (Throwable) {
            return $this->result('configuration_required', 'The SDK could not validate the regional machine identity. Ask your administrator to review the connection setup.');
        }
    }

    public function verifyCredentials(string $baseUrl, string $token): array
    {
        if (! app()->environment(['local', 'testing']) || config('maxx-ai.allow_provisional') !== true) {
            return $this->result('blocked', 'This SDK release requires an approved local or testing environment.');
        }
        if (! preg_match('/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/', $token)) {
            return $this->result('configuration_required', 'Enter a valid Universal AI Client Access credential.');
        }

        try {
            $identity = UsageIdentity::current();
            RegionalDatabase::connection($identity->region);
            $response = app(RegionalSdkCredentialVerifier::class)->scopeKeys($baseUrl, $token, new ActionContext(
                (string) $identity->tenantId, (string) $identity->userId, 'ai-governance', 'connection', 'check',
            ));

            return $this->connectedResponse($response);
        } catch (ApiException $error) {
            return $this->apiFailure($error);
        } catch (TransportException) {
            return $this->result('unavailable', 'The AI backend could not be reached. Try checking again later.', true);
        } catch (ProtocolException) {
            return $this->result('invalid_response', 'The AI backend returned an unexpected connection response.', true);
        } catch (SdkException) {
            return $this->result('invalid_response', 'The AI backend returned an unexpected connection response.', true);
        } catch (Throwable) {
            return $this->result('configuration_required', 'The SDK could not validate this regional connection configuration.');
        }
    }

    private function apiFailure(ApiException $error): array
    {
        return $this->result(in_array($error->status, [401, 403], true) ? 'access_denied' : 'unavailable',
            'The AI backend did not accept this connection check. Ask your administrator to verify regional registration and access.', true);
    }

    private function connectedResponse(ApiResponse $response): array
    {
        foreach (['account', 'application', 'deployment', 'tenant', 'module', 'service', 'action', 'actor'] as $key) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', $response->string($key))) {
                return $this->result('invalid_response', 'The AI backend returned an unexpected connection response.', true);
            }
        }

        return $this->result('connected', 'The SDK authenticated successfully for your tenant. No AI generation was requested.', true);
    }

    private function result(string $state, string $message, bool $canCheck = false): array
    {
        return ['state' => $state, 'message' => $message, 'can_check' => $canCheck];
    }

    public function configuration(): array
    {
        $connection = RegionalSdkConnection::query()->find('regional');

        return [
            'base_url' => $connection?->base_url ?? config('maxx-ai.base_url'),
            'has_credential' => $connection !== null && filled($connection->access_token),
            'enabled' => $connection?->enabled ?? (config('maxx-ai.enabled') === true),
            'execution' => app(ExecutionSettings::class)->current($connection),
        ];
    }

    public function saveVerified(#[\SensitiveParameter] array $data): void
    {
        $identity = UsageIdentity::current();
        abort_unless(GovernanceAccess::canManageSdk(auth()->user()), 403, 'Regional administrator permission is required.');
        RegionalDatabase::connection($identity->region);
        $connection = RegionalSdkConnection::query()->find('regional') ?? new RegionalSdkConnection();
        $connection->id = 'regional';
        $connection->base_url = rtrim($data['base_url'], '/');
        $connection->access_token = $data['access_token'];
        $connection->enabled = true;
        $connection->verified_at = now('UTC');
        $connection->fill(array_intersect_key($data, ExecutionSettings::rules()));
        $connection->save();

        foreach ([Settings::class, TokenProvider::class, Transport::class, GovernanceClient::class, ExecutionClient::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }
}
