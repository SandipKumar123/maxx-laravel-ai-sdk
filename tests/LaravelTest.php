<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Maxx\Ai\Auth\PlatformTokenProvider;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Billing\BillingEvent;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Billing\BillingVerifier;
use Maxx\Ai\Billing\InvoiceHandler;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\SdkException;
use Maxx\Ai\Exceptions\TransportException;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Http\Transport;
use Maxx\Ai\Laravel\BillingWebhookController;
use Maxx\Ai\Laravel\MaxxAiServiceProvider;
use Maxx\Ai\Testing\FakeTransport;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

final class LaravelTest extends TestCase
{
    private FakeTransport $fake;
    private CountingInvoiceHandler $handler;

    protected function getPackageProviders($app): array
    {
        return [MaxxAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('maxx-ai.enabled', true);
        $app['config']->set('maxx-ai.allow_provisional', true);
        $app['config']->set('maxx-ai.machine.account_id', 'customer-1');
        $app['config']->set('maxx-ai.machine.application_id', 'hrms-local');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $migration = require __DIR__.'/../database/migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php';
        $migration->up();
        Schema::create('test_invoice_lines', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('reference');
        });
        $this->fake = new FakeTransport();
        $this->handler = new CountingInvoiceHandler();
        $this->app->instance(Transport::class, $this->fake);
        $this->app->instance(InvoiceHandler::class, $this->handler);
        $this->app->instance(BillingVerifier::class, new BillingVerifier(
            ['current' => Keys::pair()['public']], 'customer-1', 'hrms-local',
        ));
        Route::post('/maxx-ai/billing', BillingWebhookController::class);
    }

    private function processor(): BillingProcessor
    {
        return $this->app->make(BillingProcessor::class);
    }

    public function testProviderResolvesSettingsAndPublishesWithoutAutomaticMigrationsOrRoutes(): void
    {
        self::assertSame('testing', $this->app->make(Settings::class)->environment);
        self::assertNotEmpty(MaxxAiServiceProvider::pathsToPublish(MaxxAiServiceProvider::class, 'maxx-ai-config'));
        self::assertNotEmpty(MaxxAiServiceProvider::pathsToPublish(MaxxAiServiceProvider::class, 'maxx-ai-migrations'));
        self::assertSame(1, collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === 'maxx-ai/billing')->count());
    }

    public function testDuplicateDeliveryCreatesOneInvoiceAndReusesStableAcknowledgment(): void
    {
        $this->fake->enqueue(['acknowledged' => true])->enqueue(['acknowledged' => true]);
        $signed = Keys::event();
        self::assertSame('invoice-event-1', $this->processor()->process($signed));
        self::assertSame('invoice-event-1', $this->processor()->process($signed));
        self::assertSame(1, $this->handler->calls);
        self::assertSame(1, DB::table('test_invoice_lines')->count());
        self::assertSame(1, DB::table('maxx_ai_billing_inbox')->count());
        self::assertNotNull(DB::table('maxx_ai_billing_inbox')->first()->acknowledged_at);
        self::assertSame($this->fake->requests()[0]['body'], $this->fake->requests()[1]['body']);
    }

    public function testAcknowledgmentTimeoutRetainsInvoiceAndReplayDoesNotInvoiceTwice(): void
    {
        $this->fake->enqueue(new TransportException(true, 'correlation'))->enqueue(['acknowledged' => true]);
        $signed = Keys::event();
        try {
            $this->processor()->process($signed);
            self::fail('Expected acknowledgment failure.');
        } catch (TransportException) {
            self::assertSame(1, DB::table('test_invoice_lines')->count());
            self::assertNull(DB::table('maxx_ai_billing_inbox')->first()->acknowledged_at);
        }
        self::assertSame('invoice-event-1', $this->processor()->process($signed));
        self::assertSame(1, $this->handler->calls);
        self::assertNotNull(DB::table('maxx_ai_billing_inbox')->first()->acknowledged_at);
    }

    public function testInvoiceFailureRollsBackInboxAndInvoiceAndNeverAcknowledges(): void
    {
        $this->handler->fail = true;
        try {
            $this->processor()->process(Keys::event());
            self::fail('Expected host invoicing failure.');
        } catch (\DomainException $error) {
            self::assertSame('Host invoice failure.', $error->getMessage());
        }
        self::assertSame(0, DB::table('test_invoice_lines')->count());
        self::assertSame(0, DB::table('maxx_ai_billing_inbox')->count());
        self::assertCount(0, $this->fake->requests());
    }

    public function testChangedPayloadForSameEventIsRejected(): void
    {
        $this->fake->enqueue(['acknowledged' => true]);
        $this->processor()->process(Keys::event());
        try {
            $this->processor()->process(Keys::event(['amount' => '99']));
            self::fail('Expected immutable payload conflict.');
        } catch (SdkException $error) {
            self::assertSame('Conflicting immutable billing event.', $error->getMessage());
        }
        self::assertSame(1, $this->handler->calls);
        self::assertCount(1, $this->fake->requests());
    }

    public function testWebhookVerifiesDeliveryAndDuplicatesRemainSafe(): void
    {
        $signed = Keys::event();
        $body = ['signed_event' => $signed, 'delivery' => Keys::delivery($signed)];
        $this->fake->enqueue(['acknowledged' => true])->enqueue(['acknowledged' => true]);
        $this->postJson('/maxx-ai/billing', $body)->assertOk()->assertExactJson(['processed' => true]);
        $this->postJson('/maxx-ai/billing', $body)->assertOk();
        self::assertSame(1, $this->handler->calls);
    }

    public function testForgedWebhookNeverTouchesInboxOrInvoicer(): void
    {
        $signed = Keys::event();
        $this->postJson('/maxx-ai/billing', [
            'signed_event' => $signed,
            'delivery' => Keys::delivery($signed, ['payload_hash' => str_repeat('0', 64)]),
        ])->assertUnauthorized()->assertExactJson(['code' => 'UNTRUSTED_DELIVERY']);
        self::assertSame(0, DB::table('maxx_ai_billing_inbox')->count());
        self::assertSame(0, $this->handler->calls);
    }

    public function testPollingFollowsBoundedCursorAndAcknowledgesEveryVerifiedEvent(): void
    {
        $this->fake
            ->enqueue(['events' => [['event_id' => 'event-1', 'signed_event' => Keys::event()]], 'next_cursor' => 'event-1'])
            ->enqueue(['acknowledged' => true])
            ->enqueue(['events' => [['event_id' => 'event-2', 'signed_event' => Keys::event(['event_id' => 'event-2'])]], 'next_cursor' => null])
            ->enqueue(['acknowledged' => true]);
        $this->artisan('maxx-ai:billing-pull', ['--limit' => 1, '--max-pages' => 2])
            ->expectsOutput('Processed 2 billing deliveries.')->assertSuccessful();
        self::assertSame(2, $this->handler->calls);
        self::assertSame('event-1', $this->fake->requests()[2]['query']['cursor']);
    }

    public function testPollingRejectsInvalidBoundsWithoutRequests(): void
    {
        $this->artisan('maxx-ai:billing-pull', ['--max-pages' => 0])->assertExitCode(2);
        self::assertCount(0, $this->fake->requests());
    }

    public function testEnvelopeMismatchRejectedBeforeInvoice(): void
    {
        $this->expectException(SdkException::class);
        $this->processor()->process(Keys::event(), 'different-event');
    }

    public function testProcessingInsideOuterTransactionRejected(): void
    {
        DB::beginTransaction();
        try {
            $this->processor()->process(Keys::event());
            self::fail('Expected nested transaction refusal.');
        } catch (SdkException $error) {
            self::assertStringContainsString('existing transaction', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        self::assertCount(0, $this->fake->requests());
    }

    public function testUnconfirmedAcknowledgmentIsNotRecordedAsSuccess(): void
    {
        $this->fake->enqueue(['acknowledged' => false]);
        try {
            $this->processor()->process(Keys::event());
            self::fail('Expected invalid acknowledgment error.');
        } catch (SdkException) {
            self::assertNull(DB::table('maxx_ai_billing_inbox')->first()->acknowledged_at);
            self::assertSame(1, DB::table('test_invoice_lines')->count());
        }
    }

    public function testRemoteAcknowledgmentConflictIsSurfacedWithoutDeletingEvidence(): void
    {
        $this->fake->enqueue(new ApiException(409, 'BILLING_ACKNOWLEDGMENT_CONFLICT', 'correlation'));
        try {
            $this->processor()->process(Keys::event());
            self::fail('Expected ack conflict.');
        } catch (ApiException $error) {
            self::assertSame(409, $error->status);
            self::assertSame(1, DB::table('maxx_ai_billing_inbox')->count());
        }
    }

    public function testInboxDowngradeCannotDeleteEvidence(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php';
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function testDisabledSdkFailsWhenResolved(): void
    {
        $this->app['config']->set('maxx-ai.enabled', false);
        $this->expectException(\LogicException::class);
        $this->app->make(Settings::class);
    }

    public function testPlatformModeUsesIssuedSecretWithoutMachinePrivateKey(): void
    {
        $this->app['config']->set('maxx-ai.auth_mode', 'platform');
        $this->app['config']->set('maxx-ai.access_token', 'maxx_sdk_'.str_repeat('b', 43));
        $provider = $this->app->make(TokenProvider::class);
        self::assertInstanceOf(PlatformTokenProvider::class, $provider);
        self::assertSame('maxx_sdk_'.str_repeat('b', 43), $provider->token());
    }

    public function testMissingPlatformSecretFailsExplicitly(): void
    {
        $this->app['config']->set('maxx-ai.auth_mode', 'platform');
        $this->app['config']->set('maxx-ai.access_token', null);
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(TokenProvider::class);
    }

    public function testUnknownAuthenticationModeFailsExplicitly(): void
    {
        $this->app['config']->set('maxx-ai.auth_mode', 'invalid');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(TokenProvider::class);
    }

    public function testConcurrentProcessesInvoiceSameEventOnlyOnce(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maxx-sdk-'.bin2hex(random_bytes(10));
        self::assertTrue(mkdir($directory));
        $database = $directory.DIRECTORY_SEPARATOR.'inbox.sqlite';
        $public = $directory.DIRECTORY_SEPARATOR.'public.pem';
        $event = $directory.DIRECTORY_SEPARATOR.'event.txt';
        $processes = [];
        try {
            touch($database);
            file_put_contents($public, Keys::pair()['public']);
            file_put_contents($event, Keys::event());
            $this->app['config']->set('database.connections.concurrent', [
                'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 10000,
            ]);
            $this->app['config']->set('database.default', 'concurrent');
            $migration = require __DIR__.'/../database/migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php';
            $migration->up();
            Schema::create('test_invoice_lines', function (Blueprint $table): void {
                $table->string('event_id')->primary();
                $table->string('reference');
            });
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, __DIR__.'/fixtures/process-billing.php', $database, $public, $event]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                self::assertSame('invoice-event-1', $process->getOutput());
            }
            self::assertSame(1, DB::table('test_invoice_lines')->count());
            self::assertSame(1, DB::table('maxx_ai_billing_inbox')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::purge('concurrent');
            foreach ([$database, $database.'-journal', $public, $event] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}

final class CountingInvoiceHandler implements InvoiceHandler
{
    public int $calls = 0;
    public bool $fail = false;

    public function handle(BillingEvent $event): string
    {
        $this->calls++;
        $reference = 'invoice-'.$event->eventId;
        DB::table('test_invoice_lines')->insert(['event_id' => $event->eventId, 'reference' => $reference]);
        if ($this->fail) {
            throw new \DomainException('Host invoice failure.');
        }

        return $reference;
    }
}
