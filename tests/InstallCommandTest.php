<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Illuminate\Filesystem\Filesystem;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Laravel\MaxxAiServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;

final class InstallCommandTest extends TestCase
{
    private string $host;
    private Filesystem $files;

    protected function getPackageProviders($app): array
    {
        return [MaxxAiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem();
        $this->host = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maxx-ai-install-'.bin2hex(random_bytes(8));
        $this->files->ensureDirectoryExists($this->host.'/bootstrap');
        $this->files->ensureDirectoryExists($this->host.'/config');
        $this->files->put($this->host.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'Modules/']],
        ], JSON_THROW_ON_ERROR));
        $this->files->put($this->host.'/bootstrap/providers.php', <<<'PHP'
<?php

return [
    // Keep host providers in their original order.
    App\Providers\ZebraServiceProvider::class,
    App\Providers\AlphaServiceProvider::class,
];
PHP);
        $this->app->setBasePath($this->host);
        $this->app->useBootstrapPath($this->host.'/bootstrap');
        $this->app->useConfigPath($this->host.'/config');
    }

    protected function tearDown(): void
    {
        try {
            $this->files->deleteDirectory($this->host);
        } finally {
            parent::tearDown();
        }
    }

    public function testInstallsTheEntireBundledModuleAndRegistersItsProvider(): void
    {
        $this->artisan('maxx-ai:install')
            ->expectsOutput('AiGovernance module installed in Modules/AiGovernance.')
            ->assertSuccessful();

        $source = dirname(__DIR__).'/stubs/AiGovernance';
        $sourceFiles = $this->files->allFiles($source);
        $installedFiles = $this->files->allFiles($this->host.'/Modules/AiGovernance');
        self::assertCount(count($sourceFiles), $installedFiles);
        foreach ($sourceFiles as $file) {
            $installed = $this->host.'/Modules/AiGovernance/'.$file->getRelativePathname();
            self::assertFileExists($installed);
            self::assertSame(hash_file('sha256', $file->getPathname()), hash_file('sha256', $installed));
        }
        self::assertSame([
            'App\\Providers\\ZebraServiceProvider',
            'App\\Providers\\AlphaServiceProvider',
            'Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider',
        ], require $this->host.'/bootstrap/providers.php');
        self::assertStringContainsString('// Keep host providers in their original order.', $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertSame(
            $this->files->get(dirname(__DIR__).'/config/maxx-ai.php'),
            $this->files->get($this->host.'/config/maxx-ai.php'),
        );
        self::assertSame([], glob($this->host.'/Modules/.ai-governance-install-*'));
        self::assertFalse($this->app['config']->get('maxx-ai.enabled'));
        $this->expectException(\LogicException::class);
        $this->app->make(Settings::class);
    }

    public function testPreservesExistingHostConfiguration(): void
    {
        $configuration = "<?php\nreturn ['host_owned' => true];\n";
        $this->files->put($this->host.'/config/maxx-ai.php', $configuration);
        $this->artisan('maxx-ai:install')->assertSuccessful();
        self::assertSame($configuration, $this->files->get($this->host.'/config/maxx-ai.php'));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testInstalledProviderLoadsModuleRoutesViewsAndMigrationPaths(): void
    {
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Modules\\AiGovernance\\', $this->host.'/Modules/AiGovernance');
        $loader->register();
        class_alias(\Maxx\Ai\Tests\ModuleFixtures\AiGovernance\Controller::class, 'App\\Http\\Controllers\\Controller');

        try {
            $this->app->register('Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider');
            $routes = $this->app['router']->getRoutes();
            $routes->refreshNameLookups();
            foreach (['index', 'analytics', 'requests', 'sdk', 'connection-check', 'connection-save', 'execution-status'] as $name) {
                $route = $routes->getByName('ai-governance.'.$name);
                self::assertNotNull($route, $name);
                self::assertContains('auth', $route->gatherMiddleware());
                self::assertContains('regional.scope', $route->gatherMiddleware());
            }
            foreach (['overview', 'analytics', 'requests', 'sdk', 'connection-check', 'connection-save', 'execution-status'] as $name) {
                $route = $routes->getByName('api.ai-governance.'.$name);
                self::assertNotNull($route, $name);
                self::assertContains('auth:sanctum', $route->gatherMiddleware());
                self::assertContains('regional.scope', $route->gatherMiddleware());
                self::assertNotContains('web', $route->gatherMiddleware());
            }
            self::assertTrue($this->app['view']->exists('ai-governance::index'));
            self::assertContains(
                realpath($this->host.'/Modules/AiGovernance/Database/Migrations'),
                array_map('realpath', $this->app['migrator']->paths()),
            );
        } finally {
            $loader->unregister();
        }
    }

    public function testRefusesToOverwriteAnExistingModuleWithoutOtherChanges(): void
    {
        $this->files->ensureDirectoryExists($this->host.'/Modules/AiGovernance');
        $this->files->put($this->host.'/Modules/AiGovernance/host-owned.php', 'host changes');
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');

        $this->artisan('maxx-ai:install')
            ->expectsOutput('Modules/AiGovernance already exists. Installation stopped; no files were overwritten.')
            ->assertFailed();

        self::assertSame('host changes', $this->files->get($this->host.'/Modules/AiGovernance/host-owned.php'));
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertFileDoesNotExist($this->host.'/config/maxx-ai.php');
        self::assertFileDoesNotExist($this->host.'/Modules/AiGovernance/module.json');
    }

    public function testMissingModuleAutoloadMappingFailsBeforeWriting(): void
    {
        $this->files->put($this->host.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        $this->artisan('maxx-ai:install')->assertFailed();
        self::assertDirectoryDoesNotExist($this->host.'/Modules');
        self::assertFileDoesNotExist($this->host.'/config/maxx-ai.php');
    }

    public function testMissingProviderFileFailsBeforeWriting(): void
    {
        $this->files->delete($this->host.'/bootstrap/providers.php');
        $this->artisan('maxx-ai:install')->assertFailed();
        self::assertDirectoryDoesNotExist($this->host.'/Modules');
    }

    public function testUninstallRemovesOnlyUnchangedModuleAndProviderAndPreservesConfigurationAndData(): void
    {
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');
        $this->files->ensureDirectoryExists($this->host.'/Modules/OtherModule');
        $this->files->put($this->host.'/Modules/OtherModule/keep.php', 'host owned');
        $this->files->put($this->host.'/.env', 'MAXX_AI_ACCESS_TOKEN=keep-existing-value');
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $database = $this->app['db']->connection();
        $database->getSchemaBuilder()->create('ai_governance_usage', function ($table): void {
            $table->string('id')->primary();
        });
        $database->table('ai_governance_usage')->insert(['id' => 'retained-evidence']);
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $configuration = $this->files->get($this->host.'/config/maxx-ai.php');
        $manifest = json_decode($this->files->get($this->host.'/bootstrap/maxx-ai-installation.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256', $this->host.'/Modules/AiGovernance/module.json'), $manifest['entries']['module.json']);
        $this->files->ensureDirectoryExists($this->host.'/bootstrap/cache');
        $this->files->put($this->app->getCachedRoutesPath(), '<?php return [];');
        $this->files->put($this->app->getCachedConfigPath(), '<?php return [];');

        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertSuccessful();

        self::assertDirectoryDoesNotExist($this->host.'/Modules/AiGovernance');
        self::assertFileDoesNotExist($this->host.'/bootstrap/maxx-ai-installation.json');
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertSame($configuration, $this->files->get($this->host.'/config/maxx-ai.php'));
        self::assertSame('host owned', $this->files->get($this->host.'/Modules/OtherModule/keep.php'));
        self::assertSame('MAXX_AI_ACCESS_TOKEN=keep-existing-value', $this->files->get($this->host.'/.env'));
        self::assertSame('retained-evidence', $database->table('ai_governance_usage')->value('id'));
        self::assertFileDoesNotExist($this->app->getCachedRoutesPath());
        self::assertFileDoesNotExist($this->app->getCachedConfigPath());
        $this->artisan('maxx-ai:install')->assertSuccessful();
    }

    public function testUninstallRefusesModifiedAddedOrMissingFilesAndHiddenDirectories(): void
    {
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');
        $module = $this->host.'/Modules/AiGovernance';
        $original = $this->files->get($module.'/module.json');
        $this->files->put($module.'/module.json', $original."\n");
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        $this->files->put($module.'/module.json', $original);
        $this->files->put($module.'/.host-notes', 'keep');
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        self::assertSame('keep', $this->files->get($module.'/.host-notes'));
        $this->files->delete($module.'/.host-notes');
        $this->files->ensureDirectoryExists($module.'/.host-directory');
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        $this->files->deleteDirectory($module.'/.host-directory');
        $this->files->delete($module.'/module.json');
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertDirectoryExists($module);
        self::assertFileExists($this->host.'/bootstrap/maxx-ai-installation.json');
    }

    public function testUninstallRequiresInstallationManifestAndRefusesLegacyModules(): void
    {
        $this->files->ensureDirectoryExists($this->host.'/Modules/AiGovernance');
        $this->files->put($this->host.'/Modules/AiGovernance/host-owned.php', 'original regional module');
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        self::assertSame('original regional module', $this->files->get($this->host.'/Modules/AiGovernance/host-owned.php'));
    }

    public function testUninstallCanBeCancelledWithoutChanges(): void
    {
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');
        $this->artisan('maxx-ai:uninstall')
            ->expectsConfirmation('Remove the unchanged AiGovernance module?', 'no')
            ->assertSuccessful();
        self::assertDirectoryExists($this->host.'/Modules/AiGovernance');
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertFileExists($this->host.'/bootstrap/maxx-ai-installation.json');
    }

    public function testUninstallRefusesCustomizedProviderRegistration(): void
    {
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $providers = "<?php\nreturn [\n    'Modules\\\\AiGovernance\\\\Providers\\\\AiGovernanceServiceProvider',\n];\n";
        $this->files->put($this->host.'/bootstrap/providers.php', $providers);
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertFailed();
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
        self::assertDirectoryExists($this->host.'/Modules/AiGovernance');
    }

    public function testUninstallAllowsAlreadyRemovedProviderRegistration(): void
    {
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');
        $this->artisan('maxx-ai:install')->assertSuccessful();
        $this->files->put($this->host.'/bootstrap/providers.php', $providers);
        $this->artisan('maxx-ai:uninstall', ['--yes' => true])->assertSuccessful();
        self::assertDirectoryDoesNotExist($this->host.'/Modules/AiGovernance');
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
    }

    public function testInstallRefusesStaleManifest(): void
    {
        $this->files->put($this->host.'/bootstrap/maxx-ai-installation.json', '{}');
        $this->artisan('maxx-ai:install')->assertFailed();
        self::assertDirectoryDoesNotExist($this->host.'/Modules');
    }

    public function testProviderWithoutTrailingCommaCanBeRegistered(): void
    {
        $this->files->put($this->host.'/bootstrap/providers.php', "<?php\nreturn [\n    App\\Providers\\ZebraServiceProvider::class // host comment\n];\n");
        $this->artisan('maxx-ai:install')->assertSuccessful();
        self::assertSame([
            'App\\Providers\\ZebraServiceProvider',
            'Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider',
        ], require $this->host.'/bootstrap/providers.php');
    }

    public function testAlreadyRegisteredProviderIsNotDuplicatedOrReformatted(): void
    {
        $contents = "<?php\nreturn [\n    Modules\\AiGovernance\\Providers\\AiGovernanceServiceProvider::class,\n];\n";
        $this->files->put($this->host.'/bootstrap/providers.php', $contents);
        $this->artisan('maxx-ai:install')->assertSuccessful();
        self::assertSame($contents, $this->files->get($this->host.'/bootstrap/providers.php'));
    }

    public function testFailedCopyDoesNotPublishOrRegisterAPartialModule(): void
    {
        $filesystem = Mockery::mock(Filesystem::class)->makePartial();
        $filesystem->shouldReceive('copyDirectory')->once()->andReturnUsing(function (string $source, string $destination): bool {
            $this->files->ensureDirectoryExists($destination);
            $this->files->put($destination.'/partial.php', 'partial');

            return false;
        });
        $this->app->instance(Filesystem::class, $filesystem);
        $providers = $this->files->get($this->host.'/bootstrap/providers.php');

        try {
            $this->artisan('maxx-ai:install')->run();
            self::fail('Expected installation failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Could not copy the bundled AiGovernance module.', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->host.'/Modules/AiGovernance');
        self::assertSame([], glob($this->host.'/Modules/.ai-governance-install-*'));
        self::assertFileDoesNotExist($this->host.'/config/maxx-ai.php');
        self::assertSame($providers, $this->files->get($this->host.'/bootstrap/providers.php'));
    }
}
