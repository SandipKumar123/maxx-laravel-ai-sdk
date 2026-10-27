<?php

namespace Modules\AiGovernance\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\Data\ExecutionRequest;
use Maxx\Ai\ExecutionClient;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Http\Transport;
use Modules\AiGovernance\Exceptions\AiExecutionIncomplete;

/** Adapts existing HRMS text prompts to the Universal AI execution protocol. */
final class HrmsAiClient
{
    public function __construct(private Transport|\Closure $transport, private UsageRecorder $recorder) {}

    public function chat(string $module, string $action, array $payload, ?UsageIdentity $identity = null): Response
    {
        $identity ??= UsageIdentity::current();
        app(AiExecutionGate::class)->assertCanGenerate($identity);
        $settings = app(ExecutionSettings::class)->current();
        $database = RegionalDatabase::connection($identity->region);
        if ($database->transactionLevel() !== 0) {
            throw new \LogicException('AI execution must run outside business transactions.');
        }
        // The text-only backend does not accept chat options. Preserve output instructions
        // as prompt content; model selection and token ceilings belong to backend policy.
        $prompt = json_encode([
            'messages' => $payload['messages'] ?? $payload['input'] ?? [],
            'output_format' => $payload['response_format'] ?? $payload['text']['format'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (($payload['messages'] ?? $payload['input'] ?? []) === []) {
            throw new \InvalidArgumentException('AI messages are required.');
        }
        $id = (string) Str::uuid();
        $database->table('ai_execution_requests')->insert([
            'id' => $id, 'tenant_id' => $identity->tenantId, 'user_id' => $identity->userId,
            'region' => $identity->region, 'module' => $module, 'action' => $action,
            'payload_hash' => hash('sha256', $prompt), 'status' => 'authorizing',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $update = fn (array $values) => $database->table('ai_execution_requests')->where('id', $id)
            ->update($values + ['updated_at' => now('UTC')]);

        return $this->recorder->run($module, $action, function () use ($identity, $module, $action, $prompt, $id, $update, $settings) {
            try {
                $transport = $this->transport instanceof \Closure ? ($this->transport)() : $this->transport;
                $authorization = (new GovernanceClient($transport))->authorize(new AuthorizationRequest(
                    new ActionContext((string) $identity->tenantId, (string) $identity->userId, $module, 'hrms', $action),
                    $id, $settings['resource_id'], $settings['model_id'],
                    $settings['region'], $settings['executor_subject'],
                ));
                $claims = $authorization->data['claims'] ?? [];
                foreach (['logical_action_id', 'attempt_id', 'application_id', 'deployment_id', 'tenant_id',
                    'resource_id', 'model_id', 'region', 'jti', 'sub'] as $field) {
                    if (! is_string($claims[$field] ?? null) || $claims[$field] === '') {
                        throw new \UnexpectedValueException('Invalid AI authorization scope.');
                    }
                }
                // M2 namespaces the HRMS tenant with its registered application/deployment.
                $backendTenant = BackendTenant::id($claims['application_id'], $claims['deployment_id'], $identity);
                if ($claims['tenant_id'] !== $backendTenant
                    || $claims['logical_action_id'] !== $authorization->string('logical_action_id')
                    || $claims['resource_id'] !== $settings['resource_id']
                    || $claims['model_id'] !== $settings['model_id']
                    || $claims['region'] !== $settings['region']
                    || $claims['sub'] !== $settings['executor_subject']
                    || ! is_int($claims['max_output_tokens'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid AI authorization scope.');
                }
                $token = $authorization->string('authorization');
                $update(['logical_action_id' => $claims['logical_action_id'],
                    'authorization' => Crypt::encryptString($token), 'status' => 'submitting']);
                $executions = new ExecutionClient($transport);
                $submitted = $executions->execute(new ExecutionRequest(
                    $claims['logical_action_id'], $claims['attempt_id'], $claims['application_id'],
                    $claims['deployment_id'], $claims['tenant_id'], $claims['resource_id'],
                    $claims['model_id'], $claims['region'], $claims['jti'], $prompt, $claims['max_output_tokens'],
                ), $token);
                $executionId = $submitted->string('execution_id');
                $update(['execution_id' => $executionId, 'status' => 'pending']);
                $attempts = max(1, min(60, (int) config('hrms-ai.poll_attempts', 60)));
                $deadline = microtime(true) + max(1, min(60, (int) config('hrms-ai.poll_timeout_seconds', 45)));
                for ($i = 0; $i < $attempts; $i++) {
                    $result = $executions->status($executionId, $token);
                    $status = $result->data['status'] ?? null;
                    $tracked = $this->recordResult($id, $executionId, $identity, $result);
                    if ($status === 'succeeded' || ($status === 'reconciled' && ($result->data['technical_outcome'] ?? null) === 'succeeded')) {
                        $text = $result->data['result']['output_text'] ?? null;
                        if (! is_string($text) || trim($text) === '') {
                            throw new \UnexpectedValueException('AI execution returned no text.');
                        }
                        $update(['status' => 'completed']);

                        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
                            'model' => $claims['model_id'], 'status' => 'completed',
                            'choices' => [['message' => ['content' => $text]]], 'output_text' => $text,
                            'usage' => $tracked['usage'],
                        ], JSON_THROW_ON_ERROR)));
                    }
                    if (in_array($status, ['failed_known', 'outcome_unknown', 'reconciled'], true)) {
                        throw new AiExecutionIncomplete($id, $status === 'outcome_unknown');
                    }
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                    if ($i + 1 < $attempts) {
                        usleep(max(0, min(1000, (int) config('hrms-ai.poll_interval_ms', 500))) * 1000);
                    }
                }
                throw new AiExecutionIncomplete($id);
            } catch (\Throwable $error) {
                if (! $error instanceof AiExecutionIncomplete) {
                    $update(['status' => 'unknown']);
                }
                throw $error;
            }
        }, 'maxx-ai', $identity, $settings['model_id'], $id);
    }

    public function status(string $requestId): array
    {
        $identity = UsageIdentity::current();
        app(AiExecutionGate::class)->assertCanGenerate($identity);
        $database = RegionalDatabase::connection($identity->region);
        $request = $database->table('ai_execution_requests')->where('id', $requestId)
            ->where('tenant_id', $identity->tenantId)->where('user_id', $identity->userId)
            ->where('region', $identity->region)->first();
        abort_unless($request, 404);
        if (! $request->execution_id || ! $request->authorization) {
            return ['request_id' => $requestId, 'status' => $request->status];
        }
        $transport = $this->transport instanceof \Closure ? ($this->transport)() : $this->transport;
        $result = (new ExecutionClient($transport))->status($request->execution_id, Crypt::decryptString($request->authorization));
        $tracked = $this->recordResult($requestId, $request->execution_id, $identity, $result);

        return ['request_id' => $requestId, 'execution_id' => $request->execution_id, 'status' => $tracked['status']];
    }

    private function recordResult(string $requestId, string $executionId, UsageIdentity $identity, ApiResponse $result): array
    {
        if (($result->data['execution_id'] ?? null) !== $executionId) {
            throw new \UnexpectedValueException('Invalid AI execution identity.');
        }
        $state = $result->data['status'] ?? null;
        $status = match ($state) {
            'succeeded' => 'completed', 'failed_known' => 'failed', 'outcome_unknown' => 'unknown',
            'accepted', 'queued', 'pending', 'dispatching', 'running' => 'pending',
            'reconciled' => match ($result->data['technical_outcome'] ?? null) {
                'succeeded' => 'completed', 'failed_known' => 'failed',
                default => throw new \UnexpectedValueException('Invalid AI execution outcome.'),
            },
            default => throw new \UnexpectedValueException('Invalid AI execution status.'),
        };
        $native = $result->data['usage'] ?? null;
        $usage = null;
        if ($native !== null) {
            $categories = ['input_tokens', 'output_tokens', 'cached_input_tokens',
                'cache_creation_input_tokens', 'cache_read_input_tokens'];
            if (! is_array($native) || ! array_key_exists('input_tokens', $native)
                || ! array_key_exists('output_tokens', $native)
                || array_diff(array_keys($native), $categories) !== []) {
                throw new \UnexpectedValueException('Invalid AI execution usage.');
            }
            foreach ($native as $count) {
                if (! is_int($count) || $count < 0 || $count > 1_000_000_000) {
                    throw new \UnexpectedValueException('Invalid AI execution usage.');
                }
            }
            // M1 reports cached input as separate, non-overlapping native categories.
            $input = $native['input_tokens'] + ($native['cached_input_tokens'] ?? 0)
                + ($native['cache_creation_input_tokens'] ?? 0) + ($native['cache_read_input_tokens'] ?? 0);
            $usage = ['input_tokens' => $input, 'output_tokens' => $native['output_tokens'],
                'total_tokens' => $input + $native['output_tokens']];
        }
        $database = RegionalDatabase::connection($identity->region);
        $database->transaction(function () use ($database, $requestId, $identity, $status, $usage): void {
            foreach (['ai_execution_requests', 'ai_governance_usage'] as $table) {
                $values = ['status' => $status, 'updated_at' => now('UTC')];
                if ($table === 'ai_governance_usage' && $usage !== null) {
                    $values += $usage;
                }
                $database->table($table)->where('id', $requestId)->where('tenant_id', $identity->tenantId)
                    ->where('user_id', $identity->userId)->where('region', $identity->region)->update($values);
            }
        });

        return ['status' => $status, 'usage' => $usage];
    }
}
