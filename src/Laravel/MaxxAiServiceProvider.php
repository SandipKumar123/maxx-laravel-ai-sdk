<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Auth\PlatformTokenProvider;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Billing\BillingVerifier;
use Maxx\Ai\Billing\InvoiceHandler;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\Http\GuzzleTransport;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Http\Transport;

final class MaxxAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/maxx-ai.php', 'maxx-ai');
        $this->app->singleton(Settings::class, function ($app): Settings {
            if ($app['config']->get('maxx-ai.enabled') !== true) {
                throw new \LogicException('Maxx AI SDK is disabled; configure and explicitly enable it first.');
            }

            return new Settings(
                $app['config']->get('maxx-ai.base_url'),
                $app->environment(),
                $app['config']->get('maxx-ai.allow_provisional'),
                (float) $app['config']->get('maxx-ai.timeout'),
                (float) $app['config']->get('maxx-ai.connect_timeout'),
            );
        });
        $this->app->bindIf(TokenProvider::class, function ($app): TokenProvider {
            $app->make(Settings::class);
            $mode = $app['config']->get('maxx-ai.auth_mode');
            if ($mode === 'platform') {
                $token = $app['config']->get('maxx-ai.access_token');
                if (!is_string($token)) {
                    throw new \InvalidArgumentException('Configure MAXX_AI_ACCESS_TOKEN for platform authentication.');
                }

                return new PlatformTokenProvider($token);
            }
            if ($mode !== 'machine') {
                throw new \InvalidArgumentException('MAXX_AI_AUTH_MODE must be machine or platform.');
            }
            $config = $app['config']->get('maxx-ai.machine');

            return new MachineTokenProvider(
                self::readKey($config['private_key_path']),
                $config['kid'], $config['issuer'], $config['subject'],
                $config['account_id'], $config['application_id'], $config['deployment_id'],
                $config['permissions'], $config['ttl'],
            );
        }, true);
        $this->app->bindIf(Transport::class, fn ($app) => new GuzzleTransport(
            new Client(), $app->make(Settings::class), $app->make(TokenProvider::class),
        ), true);
        $this->app->singleton(GovernanceClient::class, fn ($app) => new GovernanceClient($app->make(Transport::class)));
        $this->app->singleton(ExecutionClient::class, fn ($app) => new ExecutionClient($app->make(Transport::class)));
        $this->app->singleton(BillingVerifier::class, function ($app): BillingVerifier {
            $app->make(Settings::class);
            $config = $app['config']->get('maxx-ai');
            $keys = [];
            foreach ($config['billing']['public_key_paths'] as $kid => $path) {
                $keys[$kid] = self::readKey($path);
            }

            return new BillingVerifier(
                $keys, $config['machine']['account_id'], $config['machine']['application_id'],
                $config['billing']['issuer'],
            );
        });
        $this->app->singleton(BillingProcessor::class, function ($app): BillingProcessor {
            if (!$app->bound(InvoiceHandler::class)) {
                throw new \LogicException('Bind a host-owned InvoiceHandler before processing billing events.');
            }

            return new BillingProcessor(
                $app['db']->connection($app['config']->get('maxx-ai.billing.connection')),
                $app->make(BillingVerifier::class), $app->make(InvoiceHandler::class),
                $app->make(GovernanceClient::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/maxx-ai.php' => config_path('maxx-ai.php')], 'maxx-ai-config');
        $this->publishes([
            __DIR__.'/../../database/migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php'
                => database_path('migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php'),
        ], 'maxx-ai-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([PullBillingCommand::class]);
        }
    }

    private static function readKey(?string $path): string
    {
        if ($path === null || $path === '' || str_contains($path, '://')
            || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Configure a readable local key file outside the public web directory.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the configured key file.');
        }

        return $contents;
    }
}
