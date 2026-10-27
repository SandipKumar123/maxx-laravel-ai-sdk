<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Maxx\Ai\Laravel\MaxxAiServiceProvider;
use Maxx\Ai\Testing\FakeTransport;
use Maxx\Ai\Tests\ModuleFixtures\AiGovernance\RegionalScope;
use Maxx\Ai\Tests\ModuleFixtures\AiGovernance\ScopeContext;
use Maxx\Ai\Tests\ModuleFixtures\AiGovernance\User;
use Modules\AiGovernance\Models\RegionalSdkConnection;
use Modules\AiGovernance\Providers\AiGovernanceServiceProvider;
use Modules\AiGovernance\Services\HrmsAiClient;
use Modules\AiGovernance\Services\RegionalSdkCredentialVerifier;
use Modules\AiGovernance\Services\UsageRecorder;
use Orchestra\Testbench\TestCase;

final class AiGovernanceApiTest extends TestCase
{
    private static ClassLoader $moduleLoader;
    private User $user;
    private string $token;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$moduleLoader = new ClassLoader();
        self::$moduleLoader->addPsr4('Modules\\AiGovernance\\', dirname(__DIR__).'/stubs/AiGovernance');
        self::$moduleLoader->register();
        class_alias(User::class, 'App\\Models\\User');
        class_alias(ScopeContext::class, 'App\\Support\\ScopeContext');
        class_alias(\Maxx\Ai\Tests\ModuleFixtures\AiGovernance\Controller::class, 'App\\Http\\Controllers\\Controller');
    }

    public static function tearDownAfterClass(): void
    {
        self::$moduleLoader->unregister();
        parent::tearDownAfterClass();
    }

    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, MaxxAiServiceProvider::class, AiGovernanceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.jurisdiction', 'in');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('maxx-ai.enabled', false);
        $app['config']->set('maxx-ai.allow_provisional', true);
        $app['config']->set('sanctum.guard', []);
        $app['router']->aliasMiddleware('regional.scope', RegionalScope::class);
        $app->singleton('App\\Support\\ScopeContext', ScopeContext::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id');
            $table->integer('status')->default(1);
            $table->string('profile_jurisdiction_code')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('governance_access')->default(true);
            $table->boolean('sdk_manage')->default(false);
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        foreach (glob(dirname(__DIR__).'/stubs/AiGovernance/Database/Migrations/*.php') as $path) {
            (require $path)->up();
        }
        Gate::define('ai-governance.index', fn (User $user) => (bool) $user->governance_access);
        Gate::define('ai-governance.sdk.manage', fn (User $user) => (bool) $user->sdk_manage);
        $this->user = User::create(['name' => 'Tenant administrator', 'tenant_id' => 10, 'sdk_manage' => true])->refresh();
        $this->token = $this->user->createToken('api-test')->plainTextToken;
        $this->withToken($this->token);
    }

    private function usage(array $values = []): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('ai_governance_usage')->insert($values + [
            'id' => $id, 'tenant_id' => 10, 'user_id' => $this->user->id,
            'region' => 'in', 'module' => 'recruitment', 'action' => 'draft',
            'provider' => 'maxx-ai', 'status' => 'completed',
            'input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30,
            'created_at' => '2026-10-25 12:00:00', 'updated_at' => '2026-10-25 12:00:00',
        ]);

        return $id;
    }

    private function connectionData(): array
    {
        return [
            'base_url' => 'https://ai.example.test', 'access_token' => 'maxx_sdk_'.str_repeat('a', 43),
            'resource_id' => 'resource-1', 'model_id' => 'model-1',
            'execution_region' => 'local', 'executor_subject' => 'executor-1',
        ];
    }

    private function backend(array $responses, array &$history): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $this->app->instance(RegionalSdkCredentialVerifier::class,
            new RegionalSdkCredentialVerifier(new Client(['handler' => $stack])));
    }

    private function scopeResponse(): Response
    {
        return new Response(200, [], json_encode(array_fill_keys(
            ['account', 'application', 'deployment', 'tenant', 'module', 'service', 'action', 'actor'],
            str_repeat('a', 64),
        ), JSON_THROW_ON_ERROR));
    }

    public function testOverviewIsJsonWithoutAcceptHeaderAndNeverMixesTenantOrRegion(): void
    {
        $this->usage();
        $this->usage(['tenant_id' => 11, 'total_tokens' => 999]);
        $this->usage(['region' => 'us', 'total_tokens' => 999]);
        $response = $this->get('/api/ai-governance/overview');
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.summary.requests', 1)
            ->assertJsonPath('data.summary.tokens', 30)
            ->assertJsonPath('data.identity.tenantId', 10)
            ->assertJsonMissingPath('data.sdk');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testEmptyOverviewCountsAreZero(): void
    {
        $this->getJson('/api/ai-governance/overview')->assertOk()
            ->assertJsonPath('data.summary', ['requests' => 0, 'tokens' => 0, 'unreported' => 0, 'failed' => 0, 'unresolved' => 0]);
    }

    public function testAnalyticsFiltersAndPaginationAreScoped(): void
    {
        $this->usage();
        $this->usage(['status' => 'failed', 'module' => 'assessment', 'total_tokens' => null]);
        $outsider = User::create(['name' => 'Other tenant', 'tenant_id' => 99]);
        $this->usage(['tenant_id' => 99, 'user_id' => $outsider->id, 'module' => 'secret']);
        $this->getJson('/api/ai-governance/analytics?from=2026-10-25&to=2026-10-25&module=recruitment')
            ->assertOk()->assertJsonPath('data.summary.requests', 1)
            ->assertJsonPath('data.byModule.0.module', 'recruitment')
            ->assertJsonPath('data.byUser.per_page', 10)
            ->assertJsonPath('data.byUser.total', 1)
            ->assertJsonMissing(['module' => 'secret']);
        $this->getJson('/api/ai-governance/overview?status=failed')->assertOk()
            ->assertJsonPath('data.summary.unreported', 1)->assertJsonPath('data.summary.failed', 1);
    }

    public function testRequestHistoryHasBoundedStablePaginationAndNoExecutionSecrets(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->usage();
        }
        $this->usage(['tenant_id' => 99]);
        $this->getJson('/api/ai-governance/requests?page=2')->assertOk()
            ->assertJsonPath('data.records.per_page', 25)->assertJsonPath('data.records.total', 26)
            ->assertJsonCount(1, 'data.records.data')
            ->assertJsonMissingPath('data.records.data.0.authorization');
    }

    public function testInvalidFiltersReturnJsonValidationErrors(): void
    {
        $this->get('/api/ai-governance/analytics?from=2026-10-26&to=2026-10-25&page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['to', 'page']);
    }

    public function testMissingInvalidExpiredAndRevokedTokensAreRejected(): void
    {
        $this->withToken('invalid')->get('/api/ai-governance/overview')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $expired = $this->user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->get('/api/ai-governance/overview')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->user->tokens()->delete();
        $this->withToken($this->token)->get('/api/ai-governance/overview')->assertUnauthorized();
        $this->flushHeaders()->get('/api/ai-governance/overview')->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function testPermissionInactiveUserAndCrossScopeInputsAreDenied(): void
    {
        $this->getJson('/api/ai-governance/overview?tenant_id=99')->assertForbidden();
        $this->getJson('/api/ai-governance/overview', ['X-Region' => 'us'])->assertForbidden();
        $this->user->update(['governance_access' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ai-governance/overview')->assertForbidden();
        $this->user->update(['governance_access' => true, 'status' => 0]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ai-governance/overview')->assertForbidden();
    }

    public function testExistingHrmsLoginTokensDoNotReplaceHostPermissions(): void
    {
        $token = $this->user->createToken('api-device')->plainTextToken;
        $this->withToken($token)->getJson('/api/ai-governance/overview')->assertOk();
        $this->user->update(['governance_access' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ai-governance/overview')->assertForbidden();
    }

    public function testConnectionReadNeverReturnsCredentialOrMakesNetworkCalls(): void
    {
        $data = $this->connectionData();
        RegionalSdkConnection::create($data + ['id' => 'regional', 'enabled' => true, 'verified_at' => now()]);
        $response = $this->getJson('/api/ai-governance/sdk-connection');
        $response->assertOk()->assertJsonPath('data.sdkConfiguration.has_credential', true)
            ->assertJsonPath('data.canManageSdk', true)->assertJsonMissingPath('data.sdkConfiguration.access_token');
        self::assertStringNotContainsString($data['access_token'], $response->getContent());
    }

    public function testConnectionMutationRequiresManagerAndValidatesAllSixFields(): void
    {
        $this->user->update(['sdk_manage' => false]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/ai-governance/connection-check', $this->connectionData())->assertForbidden();
        $this->putJson('/api/ai-governance/sdk-connection', $this->connectionData())->assertForbidden();
        $this->user->update(['sdk_manage' => true]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/ai-governance/connection-check', ['base_url' => 'http://remote.example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['base_url', 'access_token', 'resource_id', 'model_id', 'execution_region', 'executor_subject']);
        self::assertSame(0, RegionalSdkConnection::count());
    }

    public function testStatelessCheckDoesNotSaveAndSaveReverifiesWithoutSession(): void
    {
        $history = [];
        $this->backend([$this->scopeResponse(), $this->scopeResponse()], $history);
        $data = $this->connectionData();
        $response = $this->postJson('/api/ai-governance/connection-check', $data);
        $response->assertOk()->assertJsonPath('data.state', 'connected')->assertCookieMissing(config('session.cookie'));
        self::assertSame(0, RegionalSdkConnection::count());
        $this->putJson('/api/ai-governance/sdk-connection', $data)->assertOk()
            ->assertJsonPath('data.configuration.execution.resource_id', 'resource-1');
        self::assertCount(2, $history);
        $body = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('10', $body['external_tenant_id']);
        self::assertSame((string) $this->user->id, $body['actor_ref']);
        self::assertSame('Bearer '.$data['access_token'], $history[1]['request']->getHeaderLine('Authorization'));
        self::assertStringNotContainsString($data['access_token'], DB::table('regional_sdk_connections')->value('access_token'));
        self::assertSame($data['access_token'], RegionalSdkConnection::findOrFail('regional')->access_token);
    }

    public function testSaveRejectsFailedVerificationAndPreservesSavedConfiguration(): void
    {
        $original = $this->connectionData();
        RegionalSdkConnection::create($original + ['id' => 'regional', 'enabled' => true, 'verified_at' => now()]);
        $history = [];
        $this->backend([new Response(401, [], '{"code":"INVALID_CREDENTIAL"}')], $history);
        $changed = array_replace($original, ['access_token' => 'maxx_sdk_'.str_repeat('b', 43)]);
        $this->putJson('/api/ai-governance/sdk-connection', $changed)->assertForbidden()
            ->assertJsonPath('data.state', 'access_denied');
        self::assertSame($original['access_token'], RegionalSdkConnection::findOrFail('regional')->access_token);
    }

    public function testMalformedBackendAndProductionGateCannotSave(): void
    {
        $history = [];
        $this->backend([new Response(200, [], '<html>Not an AI API</html>')], $history);
        $this->putJson('/api/ai-governance/sdk-connection', $this->connectionData())->assertStatus(502)
            ->assertJsonPath('data.state', 'invalid_response');
        $this->app['env'] = 'production';
        DB::connection()->setDatabaseName('hrms_in');
        $this->putJson('/api/ai-governance/sdk-connection', $this->connectionData())->assertForbidden()
            ->assertJsonPath('data.state', 'blocked');
        self::assertCount(1, $history);
        self::assertSame(0, RegionalSdkConnection::count());
    }

    public function testExecutionStatusIsOwnerScopedAndDoesNotSubmitWork(): void
    {
        $data = $this->connectionData();
        RegionalSdkConnection::create($data + ['id' => 'regional', 'enabled' => true, 'verified_at' => now()]);
        $id = $this->usage(['status' => 'pending']);
        DB::table('ai_execution_requests')->insert([
            'id' => $id, 'tenant_id' => 10, 'user_id' => $this->user->id, 'region' => 'in',
            'module' => 'recruitment', 'action' => 'draft', 'payload_hash' => str_repeat('a', 64),
            'status' => 'pending', 'execution_id' => 'execution-1',
            'authorization' => \Illuminate\Support\Facades\Crypt::encryptString('m2-authorization'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fake = (new FakeTransport())->enqueue(['execution_id' => 'execution-1', 'status' => 'succeeded',
            'usage' => ['input_tokens' => 8, 'output_tokens' => 2]]);
        $this->app->instance(HrmsAiClient::class, new HrmsAiClient($fake, new UsageRecorder()));
        $this->getJson('/api/ai-governance/executions/'.$id)->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertCount(1, $fake->requests());
        self::assertSame('GET', $fake->requests()[0]['method']);
        self::assertSame(10, DB::table('ai_governance_usage')->where('id', $id)->value('total_tokens'));
        DB::table('ai_execution_requests')->where('id', $id)->update(['user_id' => 999]);
        $this->getJson('/api/ai-governance/executions/'.$id)->assertNotFound();
        self::assertCount(1, $fake->requests());
    }

    public function testExecutionBackendFailureIsExplicitAndSafe(): void
    {
        RegionalSdkConnection::create($this->connectionData() + ['id' => 'regional', 'enabled' => true, 'verified_at' => now()]);
        $id = $this->usage(['status' => 'pending']);
        DB::table('ai_execution_requests')->insert([
            'id' => $id, 'tenant_id' => 10, 'user_id' => $this->user->id, 'region' => 'in',
            'module' => 'recruitment', 'action' => 'draft', 'payload_hash' => str_repeat('a', 64),
            'status' => 'pending', 'execution_id' => 'execution-1',
            'authorization' => \Illuminate\Support\Facades\Crypt::encryptString('m2-authorization'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fake = (new FakeTransport())->enqueue(new \Maxx\Ai\Exceptions\TransportException(false, 'correlation-1'));
        $this->app->instance(HrmsAiClient::class, new HrmsAiClient($fake, new UsageRecorder()));
        $response = $this->getJson('/api/ai-governance/executions/'.$id);
        $response->assertStatus(502)->assertJsonPath('correlation_id', 'correlation-1');
        self::assertStringNotContainsString('m2-authorization', $response->getContent());
        self::assertSame('pending', DB::table('ai_execution_requests')->where('id', $id)->value('status'));
    }

    public function testWebJsonReportingRemainsCompatible(): void
    {
        $this->usage();
        $this->actingAs($this->user, 'web')->getJson('/ai-governance/analytics')->assertOk()
            ->assertJsonPath('summary.requests', 1)->assertJsonPath('records.total', 1)
            ->assertJsonPath('canManageSdk', true)->assertJsonPath('sdk.state', 'disabled');
    }

    public function testWebConnectionStillRequiresSessionVerification(): void
    {
        $history = [];
        $this->backend([$this->scopeResponse()], $history);
        $this->actingAs($this->user, 'web');
        $this->post('/ai-governance/sdk-connection', $this->connectionData())
            ->assertRedirect(route('ai-governance.sdk'))->assertSessionHasErrors('access_token');
        self::assertSame(0, RegionalSdkConnection::count());
        $this->post('/ai-governance/connection-check', $this->connectionData())
            ->assertRedirect(route('ai-governance.sdk'))->assertSessionHas('ai_governance.sdk_verified');
        $this->post('/ai-governance/sdk-connection', $this->connectionData())
            ->assertRedirect(route('ai-governance.sdk'))->assertSessionMissing('ai_governance.sdk_verified');
        self::assertSame(1, RegionalSdkConnection::count());
        self::assertCount(1, $history);
    }

    public function testConnectionChecksAreRateLimited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/ai-governance/connection-check')->assertUnprocessable();
        }
        $response = $this->postJson('/api/ai-governance/connection-check');
        $response->assertStatus(429)->assertHeader('Retry-After');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
