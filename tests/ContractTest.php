<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\Data\CompletionRequest;
use Maxx\Ai\Data\EntitlementRequest;
use Maxx\Ai\Data\ExecutionRequest;
use Maxx\Ai\Data\RecoveryRequest;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Testing\FakeTransport;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ContractTest extends TestCase
{
    public function testEveryRequestAndMachineIdentityMatchesExportedBackendSchemas(): void
    {
        $fake = new FakeTransport();
        for ($i = 0; $i < 7; $i++) {
            $fake->enqueue(['ok' => true]);
        }
        $client = new GovernanceClient($fake);
        $client->authorize(new AuthorizationRequest(
            new ActionContext('tenant-1', 'actor-1', 'hrms', 'policy', 'interpret'),
            'request-1', 'dummy-resource', 'dummy-text', 'local', 'dis-service', 'job-1',
        ));
        $client->recover('action-1', new RecoveryRequest('recovery-1'));
        $client->complete('action-1', new CompletionRequest('completion-1', 'accepted', str_repeat('a', 64), 'IN', 'v1'));
        $client->issueEntitlement(new EntitlementRequest('funding-1', '0.000000000001', true, 'payment-1'));
        $client->acknowledge('event-1', 'invoice-1');
        $client->deliverReceipt('signed-receipt');
        (new ExecutionClient($fake))->execute(
            new ExecutionRequest('action', 'attempt', 'app', 'dep', 'tenant', 'res', 'model', 'local', 'idem', 'synthetic test input', 20),
            'm2-signed-authorization',
        );
        $schemas = ['AuthorizeInput', 'RecoveryInput', 'CompletionInput', 'EntitlementInput',
            'BillingAcknowledgment', 'SignedReceiptInput', 'ExecutionRequest'];
        foreach ($fake->requests() as $index => $request) {
            $this->assertSchema($schemas[$index], $request['body']);
        }
        $pair = Keys::pair();
        $machine = new MachineTokenProvider($pair['private'], 'machine', 'issuer', 'hrms-service',
            'customer', 'app', 'deployment', ['actions:execute']);
        $claims = (array) JWT::decode($machine->token(), ['machine' => new Key($pair['public'], 'RS256')]);
        $this->assertSchema('MachineClaims', $claims);
    }

    public function testContractSnapshotHashesMatchProvenanceManifest(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../contracts/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($manifest['provisional_only']);
        foreach ($manifest['schemas'] as $name => $schema) {
            self::assertSame($schema['sha256'], hash_file('sha256', __DIR__.'/../contracts/'.$name.'.json'));
        }
    }

    private function assertSchema(string $name, array $value): void
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../contracts/'.$name.'.json'), false, 64, JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        $result = (new Validator())->validate($data, $schema);
        $errors = $result->isValid() ? [] : (new ErrorFormatter())->format($result->error());
        self::assertTrue($result->isValid(), $name.': '.json_encode($errors));
    }
}
