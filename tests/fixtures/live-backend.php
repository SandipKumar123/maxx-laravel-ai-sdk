<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Auth\PlatformTokenProvider;
use Maxx\Ai\Billing\BillingEvent;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Billing\BillingVerifier;
use Maxx\Ai\Billing\InvoiceHandler;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\Data\CompletionRequest;
use Maxx\Ai\Data\ExecutionRequest;
use Maxx\Ai\Data\RecoveryRequest;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\TransportException;
use Maxx\Ai\Exceptions\VerificationException;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Http\GuzzleTransport;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Http\Transport;

final class LiveCheckFailure extends RuntimeException {}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LiveCheckFailure($message);
    }
}

function rejected(callable $request, int $status): void
{
    try {
        $request();
    } catch (ApiException $error) {
        check($error->status === $status, 'Unexpected API denial status.');

        return;
    }
    throw new RuntimeException('Expected API rejection.');
}

try {
    [$script, $configFile, $phase] = $argv;
    $config = json_decode(file_get_contents($configFile), true, 64, JSON_THROW_ON_ERROR);
    check(preg_match('/\Auai_test_[a-f0-9]{32}_sdk\z/', $config['database']['search_path']) === 1, 'Refusing non-test database schema.');
    $tokens = isset($config['access_token']) ? new PlatformTokenProvider($config['access_token']) : new MachineTokenProvider(
        file_get_contents($config['machine_key']), 'machine-test', 'maxxsata-dummy',
        'hrms-service', 'account-1', 'app-1', 'dev-1',
        ['actions:execute', 'receipts:deliver', 'billing:read', 'billing:ack', 'completion:report', 'human:review'],
        humanActorRef: 'reviewer-1',
    );
    $settings = new Settings($config['base_url'], 'testing', true);
    $transport = new GuzzleTransport(new Client(), $settings, $tokens);
    $client = new GovernanceClient($transport);
    $executions = new ExecutionClient($transport);
    $verifier = new BillingVerifier(['billing-test' => file_get_contents($config['billing_public_key'])], 'account-1', 'app-1');
    $capsule = new Manager();
    $capsule->addConnection($config['database']);
    $database = $capsule->getConnection();
    $statePath = $config['state_file'];
    $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true, 64, JSON_THROW_ON_ERROR) : [];
    $handler = new class($database) implements InvoiceHandler {
        public function __construct(private ConnectionInterface $database) {}

        public function handle(BillingEvent $event): string
        {
            usleep(100000);
            $reference = 'sdk-invoice-'.$event->eventId;
            $this->database->table('sdk_test_invoices')->insert([
                'event_id' => $event->eventId,
                'reference' => $reference,
                'amount' => $event->amount,
                'currency' => $event->currency,
            ]);

            return $reference;
        }
    };
    $processor = new BillingProcessor($database, $verifier, $handler, $client);
    if ($phase === 'execute') {
        Schema::swap($database->getSchemaBuilder());
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_000001_create_maxx_ai_billing_inbox.php';
        $migration->up();
        $database->getSchemaBuilder()->create('sdk_test_invoices', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('reference');
            $table->decimal('amount', 50, 12);
            $table->string('currency');
        });
        $context = new ActionContext('tenant-1', 'user-1', 'hrms', 'policy', 'interpret');
        $makeRequest = static fn (string $key, string $resource = 'resource-1') => new AuthorizationRequest(
            $context, $key, $resource, 'model-1', 'local', 'dis-service', 'job-1',
        );
        $client->scopeKeys($context);
        $state['actions'] = [];
        foreach (['accepted', 'rejected', 'unknown'] as $outcome) {
            $request = $makeRequest('sdk-'.$outcome);
            $auth = $client->authorize($request);
            $duplicate = $client->authorize($request);
            check($auth->string('authorization_id') === $duplicate->string('authorization_id')
                && $auth->string('authorization') === $duplicate->string('authorization')
                && $auth->string('logical_action_id') === $duplicate->string('logical_action_id'),
                'Authorization replay changed evidence.');
            rejected(fn () => $client->authorize($makeRequest('sdk-'.$outcome, 'different-resource')), 409);
            $claims = $auth->data['claims'];
            $executionRequest = new ExecutionRequest(
                $claims['logical_action_id'], $claims['attempt_id'], $claims['application_id'],
                $claims['deployment_id'], $claims['tenant_id'], $claims['resource_id'],
                $claims['model_id'], $claims['region'], $claims['jti'],
                $outcome === 'unknown' ? 'sdk-synthetic-unknown' : 'SDK synthetic local test',
                $claims['max_output_tokens'],
            );
            $execution = $executions->execute($executionRequest, $auth->string('authorization'));
            check($execution->data === $executions->execute($executionRequest, $auth->string('authorization'))->data, 'Execution replay duplicated work.');
            $state['actions'][$outcome] = [
                'action_id' => $auth->string('logical_action_id'),
                'execution_id' => $execution->string('execution_id'),
                'authorization' => $auth->string('authorization'),
            ];
        }
        $wrongIdentity = new MachineTokenProvider(
            file_get_contents($config['machine_key']), 'machine-test', 'maxxsata-dummy',
            'hrms-service', 'wrong-account', 'app-1', 'dev-1', ['actions:execute'],
        );
        $wrongClient = new GovernanceClient(new GuzzleTransport(new Client(), $settings, $wrongIdentity));
        rejected(fn () => $wrongClient->status($state['actions']['accepted']['action_id']), 403);
        check(file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)) !== false, 'Cannot persist temporary test state.');
    } elseif ($phase === 'settle') {
        foreach ($state['actions'] as $outcome => $action) {
            $execution = $executions->status($action['execution_id'], $action['authorization']);
            check($execution->data['status'] === ($outcome === 'unknown' ? 'outcome_unknown' : 'succeeded'), 'Unexpected execution state.');
            $receipt = $executions->receipt($action['execution_id'], $action['authorization'])->string('signed_receipt');
            $client->deliverReceipt($receipt);
            $client->deliverReceipt($receipt);
            if ($outcome === 'unknown') {
                rejected(fn () => $client->recover($action['action_id'], new RecoveryRequest('sdk-recovery', 'job-1')), 409);
                check($client->status($action['action_id'])->data['attempts'] === 1, 'Unknown work was retried.');
                continue;
            }
            $completion = new CompletionRequest(
                'sdk-completion-'.$outcome, $outcome, str_repeat('a', 64), 'IN', 'sdk-v1',
                'reviewer-1', 'policy-adopted-1',
            );
            if ($outcome === 'accepted') {
                rejected(fn () => $client->complete($action['action_id'], new CompletionRequest(
                    'missing-human-evidence', 'accepted', str_repeat('a', 64), 'IN', 'sdk-v1',
                )), 403);
            }
            rejected(fn () => $client->complete($action['action_id'], new CompletionRequest(
                'wrong-jurisdiction-'.$outcome, $outcome, str_repeat('a', 64), 'US', 'sdk-v1',
            )), 403);
            $client->complete($action['action_id'], $completion);
            $client->complete($action['action_id'], $completion);
            check($client->status($action['action_id'])->string('status') === 'completed', 'Completion was not durable.');
        }
        $page = $client->billingEvents(limit: 1)->data;
        check(count($page['events']) === 1 && is_string($page['next_cursor']), 'Missing real billing pagination.');
        $next = $client->billingEvents($page['next_cursor'], 1)->data;
        check(count($next['events']) === 1 && $next['events'][0]['event_id'] !== $page['events'][0]['event_id'], 'Billing cursor repeated an event.');
        $state['events'] = array_merge($page['events'], $next['events']);
        foreach ($state['events'] as $event) {
            $verified = $verifier->event($event['signed_event']);
            if ($verified->claims['logical_action_id'] === $state['actions']['rejected']['action_id']) {
                check(preg_match('/\A0(?:\.0+)?\z/', $verified->amount) === 1, 'Rejected work was charged to customer.');
            }
        }
        check(file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)) !== false, 'Cannot persist temporary event evidence.');
    } elseif ($phase === 'ack-outage') {
        $fault = new class($transport) implements Transport {
            public function __construct(private Transport $inner) {}
            public function request(string $method, string $path, ?array $body = null, array $query = [], #[\SensitiveParameter] ?string $bearer = null): \Maxx\Ai\Data\ApiResponse
            {
                if (str_ends_with($path, '/ack')) {
                    // The backend commits the real ack, then the caller loses its response.
                    $this->inner->request($method, $path, $body, $query, $bearer);
                    throw new TransportException(true, 'simulated-lost-ack-response');
                }

                return $this->inner->request($method, $path, $body, $query, $bearer);
            }
        };
        $faulty = new BillingProcessor($database, $verifier, $handler, new GovernanceClient($fault));
        try {
            $faulty->process($state['events'][0]['signed_event']);
            throw new RuntimeException('Expected lost acknowledgment response.');
        } catch (TransportException) {
            check($database->table('sdk_test_invoices')->count() === 1, 'Invoice was lost after acknowledgment outage.');
            check($database->table('maxx_ai_billing_inbox')->first()->acknowledged_at === null, 'Unknown acknowledgment reported success.');
        }
        $processor->process($state['events'][0]['signed_event']);
        $processor->process($state['events'][0]['signed_event']);
        check($database->table('sdk_test_invoices')->count() === 1, 'Replay duplicated an invoice.');
    } elseif ($phase === 'concurrent') {
        $processor->process($state['events'][1]['signed_event']);
    } elseif ($phase === 'concurrent-webhook') {
        $webhooks = json_decode(file_get_contents($config['webhook_file']), true, 64, JSON_THROW_ON_ERROR);
        $matched = false;
        foreach ($webhooks as $webhook) {
            if ($webhook['signed_event'] !== $state['events'][1]['signed_event']) {
                continue;
            }
            try {
                $processor->processWebhook($webhook['signed_event'], 'invalid-delivery');
                throw new LiveCheckFailure('Forged delivery was accepted.');
            } catch (VerificationException) {
                // Rejection is expected; the authentic Python-generated envelope follows.
            }
            $processor->processWebhook($webhook['signed_event'], $webhook['delivery']);
            $matched = true;
        }
        check($matched, 'Missing backend-signed webhook evidence.');
    } elseif ($phase === 'verify') {
        check($database->table('sdk_test_invoices')->count() === 2, 'Expected one invoice effect per event.');
        check($database->table('maxx_ai_billing_inbox')->whereNotNull('acknowledged_at')->count() === 2, 'Acknowledgments were not durable.');
        check($client->billingEvents()->data['events'] === [], 'Acknowledged events remain in billing pull.');
        rejected(fn () => $client->acknowledge($state['events'][0]['event_id'], 'conflicting-invoice'), 409);
        $statement = $client->statement()->data;
        check($statement['held'] === '12.000000000000', 'Unknown exposure was released.');
    } elseif ($phase === 'platform') {
        check($client->statement()->data['held'] === '12.000000000000', 'Platform credential scope mismatch.');
        $actionId = $state['actions']['accepted']['action_id'];
        $context = new ActionContext('tenant-1', 'user-1', 'hrms', 'policy', 'interpret');
        $client->scopeKeys($context);
        $replay = $client->authorize(new AuthorizationRequest(
            $context, 'sdk-accepted', 'resource-1', 'model-1', 'local', 'dis-service', 'job-1',
        ));
        check($replay->string('logical_action_id') === $actionId
            && $replay->string('authorization') === $state['actions']['accepted']['authorization'],
            'Platform authentication changed authorization replay.');
        check($client->status($actionId)->string('status') === 'completed', 'Platform SDK identity did not match registered deployment.');
        rejected(fn () => $client->complete($actionId, new CompletionRequest(
            'sdk-completion-accepted', 'accepted', str_repeat('a', 64), 'IN', 'sdk-v1',
            'reviewer-1', 'policy-adopted-1',
        )), 403);
    } elseif ($phase === 'platform-revoked') {
        rejected(fn () => $client->statement(), 401);
    } else {
        throw new InvalidArgumentException('Unknown integration test phase.');
    }
    echo json_encode(['phase' => $phase, 'passed' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Neither framework exceptions nor their traces are safe to dump with live test credentials.
    fwrite(STDERR, 'Live SDK test failed: '.get_class($error).PHP_EOL);
    if ($error instanceof ApiException) {
        fwrite(STDERR, $error->status.' '.$error->errorCode.PHP_EOL);
    } elseif ($error instanceof LiveCheckFailure) {
        fwrite(STDERR, $error->getMessage().PHP_EOL);
    }
    exit(1);
}
