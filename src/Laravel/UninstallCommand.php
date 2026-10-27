<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class UninstallCommand extends Command
{
    protected $signature = 'maxx-ai:uninstall {--yes : Confirm removal of the unchanged installed module}';

    protected $description = 'Safely remove the generated AiGovernance module while retaining configuration and database evidence';

    public function handle(Filesystem $files): int
    {
        $directory = base_path('Modules/AiGovernance');
        $manifestPath = $this->laravel->bootstrapPath(ModuleInstallation::MANIFEST);
        if (is_link(dirname($directory))) {
            $this->error('Modules is a symbolic link. Automatic module removal is unsafe.');

            return self::FAILURE;
        }
        if (! $files->isFile($manifestPath) || is_link($manifestPath)) {
            $this->error('No trusted installation manifest found. Existing or manually copied modules must be removed through host review.');

            return self::FAILURE;
        }
        $manifest = json_decode($files->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1 || ! is_array($manifest['entries'] ?? null)
            || $manifest['entries'] === []) {
            $this->error('Invalid AiGovernance installation manifest. No files were removed.');

            return self::FAILURE;
        }
        $expected = $manifest['entries'];
        ksort($expected);
        try {
            $current = ModuleInstallation::snapshot($directory);
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        if ($current !== $expected) {
            $this->error('AiGovernance has modified, added, or missing files/directories. No files were removed; review and back up host changes.');

            return self::FAILURE;
        }

        $providersPath = $this->laravel->getBootstrapProvidersPath();
        if (! $files->isFile($providersPath) || ! $files->isWritable($providersPath) || is_link($providersPath)) {
            $this->error('A writable, non-symlink bootstrap/providers.php file is required for safe removal.');

            return self::FAILURE;
        }
        $providers = require $providersPath;
        if (! is_array($providers)) {
            $this->error('bootstrap/providers.php must return an array. No files were removed.');

            return self::FAILURE;
        }
        $provider = 'Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider';
        $originalContents = $files->get($providersPath);
        $contents = $originalContents;
        if (in_array($provider, $providers, true)) {
            $pattern = '/^[ \t]*\\\\?'.preg_quote($provider, '/').'::class[ \t]*,?[ \t]*(?:\r?\n|\z)/m';
            $contents = preg_replace($pattern, '', $contents, -1, $count);
            if ($contents === null || $count !== count(array_keys($providers, $provider, true))) {
                $this->error('AiGovernance provider registration was customized. Remove its registration manually before retrying.');

                return self::FAILURE;
            }
        }

        $this->warn('This removes Modules/AiGovernance and its provider registration. Database records and SDK configuration are retained.');
        if (! $this->option('yes') && ! $this->confirm('Remove the unchanged AiGovernance module?', false)) {
            $this->info('Uninstall cancelled. No files were removed.');

            return self::SUCCESS;
        }
        foreach (['config:clear', 'route:clear'] as $command) {
            if ($this->call($command) !== self::SUCCESS) {
                $this->error('Cache clearing failed; uninstall stopped before removing module files.');

                return self::FAILURE;
            }
        }
        if (ModuleInstallation::snapshot($directory) !== $expected
            || $files->get($providersPath) !== $originalContents) {
            $this->error('Module files or provider registration changed during uninstall. Removal stopped; retry after reviewing host changes.');

            return self::FAILURE;
        }
        $files->replace($providersPath, $contents);
        if (! $files->deleteDirectory($directory)) {
            throw new \RuntimeException('Provider unregistered, but module deletion failed. Preserve the installation manifest and finish removal through host review.');
        }
        if (! $files->delete($manifestPath)) {
            throw new \RuntimeException('Module removed, but the installation manifest could not be deleted: '.$manifestPath);
        }

        $this->info('AiGovernance module removed. SDK configuration, credentials, permissions, and database evidence were preserved.');
        $this->line('Remove remaining host integrations, sidebar links, schedules and direct SDK references before removing the package.');
        $this->line('Then run: composer remove maxx/laravel-ai-sdk');
        $this->line('Restart long-lived workers through your deployment process.');

        return self::SUCCESS;
    }
}
