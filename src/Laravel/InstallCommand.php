<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class InstallCommand extends Command
{
    protected $signature = 'maxx-ai:install';

    protected $description = 'Install the bundled regional HRMS AiGovernance module without overwriting existing files';

    public function handle(Filesystem $files): int
    {
        $destination = base_path('Modules/AiGovernance');
        if ($files->exists($destination) || is_link($destination)) {
            $this->error('Modules/AiGovernance already exists. Installation stopped; no files were overwritten.');

            return self::FAILURE;
        }
        $manifestPath = $this->laravel->bootstrapPath(ModuleInstallation::MANIFEST);
        if ($files->exists($manifestPath) || is_link($manifestPath)) {
            $this->error('An AiGovernance installation manifest already exists. Reconcile the previous installation before retrying.');

            return self::FAILURE;
        }

        $composerPath = base_path('composer.json');
        if (! $files->isFile($composerPath)) {
            $this->error('Run maxx-ai:install from a Laravel host with a composer.json file.');

            return self::FAILURE;
        }
        $composer = json_decode($files->get($composerPath), true, 512, JSON_THROW_ON_ERROR);
        $paths = $composer['autoload']['psr-4']['Modules\\'] ?? [];
        $paths = is_string($paths) ? [$paths] : $paths;
        if (! is_array($paths) || ! in_array('Modules', array_map(
            static fn ($path) => is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '',
            $paths,
        ), true)) {
            $this->error('Add "Modules\\\\": "Modules/" to composer.json autoload.psr-4, run composer dump-autoload, then retry.');

            return self::FAILURE;
        }

        $providersPath = $this->laravel->getBootstrapProvidersPath();
        if (! $files->isFile($providersPath) || ! $files->isWritable($providersPath)) {
            $this->error('A writable bootstrap/providers.php file is required to register AiGovernance.');

            return self::FAILURE;
        }
        $provider = 'Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider';
        $providers = require $providersPath;
        if (! is_array($providers)) {
            $this->error('bootstrap/providers.php must return an array of service provider classes.');

            return self::FAILURE;
        }
        $providerContents = $files->get($providersPath);
        if (! in_array($provider, $providers, true)) {
            $tokens = array_values(array_filter(
                token_get_all($providerContents),
                static fn ($token) => ! is_array($token)
                    || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));
            $lastEntryToken = $tokens[count($tokens) - 3] ?? null;
            $separator = in_array($lastEntryToken, ['[', ','], true) ? '' : '    ,'.PHP_EOL;
            // Preserve the host's provider order and comments rather than re-sorting the array.
            $providerContents = preg_replace(
                '/^([ \t]*)\](\s*;\s*)\z/m',
                $separator.'    '.$provider.'::class,'.PHP_EOL.'$1]$2',
                $providerContents,
                1,
                $count,
            );
            if ($providerContents === null || $count !== 1) {
                $this->error('bootstrap/providers.php must end with a closing array bracket on its own line.');

                return self::FAILURE;
            }
        }

        $source = dirname(__DIR__, 2).'/stubs/AiGovernance';
        if (! $files->isFile($source.'/module.json') || ! $files->isFile($source.'/Providers/AiGovernanceServiceProvider.php')) {
            throw new \RuntimeException('The bundled AiGovernance module is missing. Restore the SDK package before installing.');
        }

        $files->ensureDirectoryExists(dirname($destination));
        $staging = dirname($destination).'/.ai-governance-install-'.bin2hex(random_bytes(8));
        try {
            if (! $files->copyDirectory($source, $staging)) {
                throw new \RuntimeException('Could not copy the bundled AiGovernance module.');
            }
            $snapshot = ModuleInstallation::snapshot($staging);
            if ($files->exists($destination) || is_link($destination) || ! $files->moveDirectory($staging, $destination)) {
                throw new \RuntimeException('Could not install Modules/AiGovernance; no existing module will be overwritten.');
            }
        } finally {
            if ($files->isDirectory($staging) && ! $files->deleteDirectory($staging)) {
                throw new \RuntimeException('Could not clean the AiGovernance installation staging directory: '.$staging);
            }
        }
        $files->replace($manifestPath, json_encode([
            'version' => 1,
            'entries' => $snapshot,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        $configPath = config_path('maxx-ai.php');
        if (! $files->exists($configPath)) {
            $files->ensureDirectoryExists(dirname($configPath));
            if (! $files->copy(dirname(__DIR__, 2).'/config/maxx-ai.php', $configPath)) {
                throw new \RuntimeException('Module files installed, but SDK configuration could not be published.');
            }
        }
        $files->replace($providersPath, $providerContents);

        $this->info('AiGovernance module installed in Modules/AiGovernance.');
        $this->line('Provider registered; existing SDK configuration preserved. No migrations or API calls were run.');
        $this->warn('This is the regional HRMS module, not a standalone UI for arbitrary Laravel applications.');
        $this->line('Required host integration: App\\Support\\ScopeContext, App\\Models\\User, regional.scope middleware,');
        $this->line('Spatie permissions, admin.layouts.base, and app.jurisdiction (in, ae, or us).');
        $this->line('Next: composer dump-autoload; php artisan config:clear; php artisan route:clear.');
        $this->line('Review the regional database, then run: php Modules/AiGovernance/Tools/install.php');
        $this->line('Configure authorized access and the SDK connection before opening /ai-governance.');
        $this->warn('SDK execution remains local/testing-only. No credentials, billing handler, sidebar links, or business call sites were created.');

        return self::SUCCESS;
    }
}
