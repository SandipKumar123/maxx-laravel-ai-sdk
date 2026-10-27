<?php

namespace Modules\AiGovernance\Providers;

use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Client;
use LogicException;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Http\Transport;
use Maxx\Ai\Laravel\MaxxAiServiceProvider;
use Modules\AiGovernance\Models\RegionalSdkConnection;
use Modules\AiGovernance\Services\RegionalSdkTokenProvider;
use Modules\AiGovernance\Services\RegionalSdkCredentialVerifier;
use Modules\AiGovernance\Services\AiExecutionGate;
use Modules\AiGovernance\Services\RegionalAiExecutionGate;
use Modules\AiGovernance\Services\TrackedTransport;
use Modules\AiGovernance\Services\UsageRecorder;

class AiGovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Package discovery may not have run yet on a newly deployed server.
        if (class_exists(MaxxAiServiceProvider::class)) {
            $this->app->register(MaxxAiServiceProvider::class);
        }

        // Generation records the complete authorize/execute/result operation once.
        // Use the SDK transport directly so its submission is not counted twice.
        $this->app->bind(\Modules\AiGovernance\Services\HrmsAiClient::class, fn ($app) => new \Modules\AiGovernance\Services\HrmsAiClient(
            fn () => new \Maxx\Ai\Http\GuzzleTransport(new Client(), $app->make(Settings::class), $app->make(TokenProvider::class)),
            $app->make(UsageRecorder::class),
        ));
        $machineProviderFactory = $this->app->getBindings()[TokenProvider::class]['concrete'] ?? null;
        $this->app->singleton(MachineTokenProvider::class, function ($app) use ($machineProviderFactory): MachineTokenProvider {
            if (! $machineProviderFactory instanceof \Closure) {
                throw new LogicException('The Maxx AI SDK machine token provider is not registered. Install locked Composer dependencies and rebuild package discovery.');
            }

            return $machineProviderFactory($app);
        });
        $this->app->singleton(TokenProvider::class, RegionalSdkTokenProvider::class);
        $this->app->singleton(AiExecutionGate::class, RegionalAiExecutionGate::class);
        $this->app->bind(RegionalSdkCredentialVerifier::class, fn () => new RegionalSdkCredentialVerifier(new Client()));
        $this->app->singleton(Settings::class, function ($app): Settings {
            $connection = RegionalSdkConnection::query()->find('regional');
            if ($connection !== null) {
                if (! $connection->enabled) {
                    throw new LogicException('The regional Maxx AI connection is disabled.');
                }

                $baseUrl = $connection->base_url;
            } else {
                if (config('maxx-ai.enabled') !== true) {
                    throw new LogicException('Maxx AI SDK is disabled; configure and explicitly enable it first.');
                }

                $baseUrl = (string) config('maxx-ai.base_url');
            }

            return new Settings(
                $baseUrl,
                $app->environment(),
                (bool) config('maxx-ai.allow_provisional'),
                (float) config('maxx-ai.timeout'),
                (float) config('maxx-ai.connect_timeout'),
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ai-governance');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Lazy decoration preserves the SDK's disabled/provisional gates.
        $this->app->extend(Transport::class, fn (Transport $transport, $app) => new TrackedTransport($transport, $app->make(UsageRecorder::class)));
    }
}
