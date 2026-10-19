<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Maxx\Ai\Auth\TokenProvider;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\Data\CompletionRequest;
use Maxx\Ai\Data\EntitlementRequest;
use Maxx\Ai\Data\ExecutionRequest;
use Maxx\Ai\Data\RecoveryRequest;
use Maxx\Ai\Data\Validate;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\SdkException;
use Maxx\Ai\Exceptions\TransportException;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Http\GuzzleTransport;
use Maxx\Ai\Http\Settings;
use Maxx\Ai\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function context(string $module = 'hrms'): ActionContext
    {
        return new ActionContext('tenant-1', 'actor-1', $module, 'policy', 'interpret');
    }

    public function testTypedClientsMapEveryMachineEndpoint(): void
    {
        $fake = new FakeTransport();
        for ($i = 0; $i < 10; $i++) {
            $fake->enqueue(['ok' => true]);
        }
        $client = new GovernanceClient($fake);
        $context = $this->context();
        $client->scopeKeys($context);
        $client->authorize(new AuthorizationRequest($context, 'request-1', 'resource-1', 'dummy-text', 'local', 'dis-service'));
        $client->status('action:1');
        $client->recover('action:1', new RecoveryRequest('recovery-1', 'job-1'));
        $client->complete('action:1', new CompletionRequest('completion-1', 'accepted', str_repeat('0', 64), 'IN', 'v1', 'human-1', 'policy-1'));
        $client->issueEntitlement(new EntitlementRequest('funding-1', '0.000000000001', true, 'payment-1'));
        $client->statement();
        $client->deliverReceipt('signed-receipt-token');
        $client->billingEvents('cursor:1', 500);
        $client->acknowledge('event:1', 'invoice:1');
        $requests = $fake->requests();
        self::assertSame([
            '/v1/actions/scope-keys', '/v1/actions/authorize', '/v1/actions/action%3A1',
            '/v1/actions/action%3A1/recoveries', '/v1/actions/action%3A1/completion',
            '/v1/entitlements', '/v1/statements', '/internal/m2/receipts',
            '/v1/billing-events', '/v1/billing-events/event%3A1/ack',
        ], array_column($requests, 'path'));
        self::assertSame('0.000000000001', $requests[5]['body']['amount']);
        self::assertSame(['limit' => 500, 'cursor' => 'cursor:1'], $requests[8]['query']);
        self::assertSame('tenant-1', $requests[1]['body']['external_tenant_id']);
        self::assertSame('human-1', $requests[4]['body']['human_actor_ref']);
    }

    public function testSamePackageSupportsHrmsAndCrmWithoutForks(): void
    {
        foreach (['hrms', 'crm'] as $module) {
            $fake = (new FakeTransport())->enqueue(['logical_action_id' => $module.'-action']);
            $result = (new GovernanceClient($fake))->authorize(
                new AuthorizationRequest($this->context($module), 'id-1', 'res', 'model', 'local', $module.'-service'),
            );
            self::assertSame($module.'-action', $result->string('logical_action_id'));
            self::assertSame($module, $fake->requests()[0]['body']['module_code']);
        }
    }

    public function testExecutionIsExplicitAndNeverAutoReplayed(): void
    {
        $fake = (new FakeTransport())->enqueue(new TransportException(true, 'correlation'))->enqueue(['status' => 'outcome_unknown']);
        $client = new ExecutionClient($fake);
        $request = new ExecutionRequest('action', 'attempt', 'app', 'dep', 'tenant', 'res', 'model', 'local', 'idem', 'private prompt', 20);
        try {
            $client->execute($request, 'authorization');
            self::fail('Expected timeout.');
        } catch (TransportException $error) {
            self::assertTrue($error->outcomeUnknown);
        }
        self::assertCount(1, $fake->requests());
        self::assertSame('outcome_unknown', $client->status('execution-1', 'authorization')->string('status'));
        self::assertSame(['POST', 'GET'], array_column($fake->requests(), 'method'));
        self::assertStringNotContainsString('private prompt', print_r($request, true));
    }

    public function testHttpTransportSetsIdentityTimeoutsTlsAndCorrelation(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"logical_action_id":"action-1"}')]));
        $stack->push(Middleware::history($history));
        $tokens = new class implements TokenProvider {
            public function token(): string { return 'test-token'; }
        };
        $transport = new GuzzleTransport(new Client(['handler' => $stack]), new Settings('http://127.0.0.1:8000', 'testing', true), $tokens);
        $response = $transport->request('POST', '/v1/actions/authorize', ['idempotency_key' => 'stable']);
        self::assertSame('action-1', $response->string('logical_action_id'));
        self::assertSame('Bearer test-token', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame($response->correlationId, $history[0]['request']->getHeaderLine('X-Correlation-ID'));
        self::assertSame('stable', json_decode((string) $history[0]['request']->getBody(), true)['idempotency_key']);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
        self::assertSame(15.0, $history[0]['options']['timeout']);
    }

    public function testHttpTimeoutNeverRetriesAndDoesNotRetainSensitiveException(): void
    {
        $calls = 0;
        $http = new Client(['handler' => function () use (&$calls): never {
            $calls++;
            throw new ConnectException('secret-token private prompt', new Request('POST', 'https://example.invalid'));
        }]);
        $tokens = new class implements TokenProvider {
            public function token(): string { return 'secret-token'; }
        };
        $transport = new GuzzleTransport($http, new Settings('https://example.invalid', 'testing', true), $tokens);
        try {
            $transport->request('POST', '/v1/actions/authorize');
            self::fail('Expected transport failure.');
        } catch (TransportException $error) {
            self::assertTrue($error->outcomeUnknown);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret-token', $error->getMessage());
        }
        self::assertSame(1, $calls);
    }

    #[DataProvider('httpErrors')]
    public function testHttpErrorsAndInvalidShapesFailExplicitly(int $status, string $body, string $exception): void
    {
        $tokens = new class implements TokenProvider {
            public function token(): string { return 'token'; }
        };
        $transport = new GuzzleTransport(
            new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, [], $body)]))]),
            new Settings('http://localhost:8000', 'testing', true), $tokens,
        );
        $this->expectException($exception);
        $transport->request('POST', '/v1/actions/authorize');
    }

    public static function httpErrors(): array
    {
        return [
            [401, '{"code":"UNAUTHENTICATED"}', ApiException::class],
            [409, '{"code":"IDEMPOTENCY_CONFLICT"}', ApiException::class],
            [503, '<html>private error</html>', ApiException::class],
            [302, '{}', ApiException::class],
            [200, '[]', SdkException::class],
            [200, 'null', SdkException::class],
            [200, '{broken', SdkException::class],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function testUnsafeSettingsRejected(string $url, string $env, bool $allow): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Settings($url, $env, $allow);
    }

    public static function invalidSettings(): array
    {
        return [
            ['http://127.0.0.1:8000', 'production', true],
            ['http://127.0.0.1:8000', 'local', false],
            ['http://remote.example', 'testing', true],
            ['https://user:secret@example.test', 'testing', true],
            ['https://example.test?token=secret', 'testing', true],
            ['https://example.test/path', 'testing', true],
            ['file:///private/key', 'testing', true],
        ];
    }

    #[DataProvider('invalidDecimals')]
    public function testInvalidFinancialStringsRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validate::decimal($value);
    }

    public static function invalidDecimals(): array
    {
        return array_map(static fn ($value) => [$value], [
            '-1', 'NaN', 'INF', '1e3', '01', '1.0000000000001', '1000000000000.000000000001', '1000000000001',
        ]);
    }

    public function testIdentifierInjectionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GovernanceClient(new FakeTransport()))->status('../other-account');
    }
}
