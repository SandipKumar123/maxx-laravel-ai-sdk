<?php

namespace Modules\AiGovernance\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Maxx\Ai\Data\ApiResponse;
use OpenAI\Exceptions\ErrorException;
use Throwable;

final class UsageRecorder
{
    public function run(string $module, string $action, callable $operation, string $provider = 'openai',
        ?UsageIdentity $identity = null, ?string $model = null, ?string $requestId = null): mixed
    {
        $identity ??= UsageIdentity::current();
        app(AiExecutionGate::class)->assertCanGenerate($identity);
        // Capture ownership once, before sending anything to the provider.
        $database = RegionalDatabase::connection($identity->region);
        if ($database->transactionLevel() !== 0) {
            throw new \LogicException('Record AI usage outside business transactions so consumption evidence is durable.');
        }
        $id = $requestId ?? (string) Str::uuid();
        $database->table('ai_governance_usage')->insert([
            'id' => $id, 'tenant_id' => $identity->tenantId, 'user_id' => $identity->userId,
            'region' => $identity->region, 'module' => $module, 'action' => $action,
            'provider' => $provider, 'model' => $model, 'status' => 'pending',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $started = hrtime(true);
        try {
            $response = $operation();
        } catch (Throwable $error) {
            // Never retain messages, prompts, responses, or credentials in the ledger.
            $unknown = property_exists($error, 'outcomeUnknown') ? $error->outcomeUnknown
                : ! ($error instanceof ErrorException);
            $database->table('ai_governance_usage')->where('id', $id)->update([
                'status' => $unknown ? 'unknown' : 'failed', 'error_type' => class_basename($error),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'updated_at' => now('UTC'),
            ]);
            throw $error;
        }

        $data = match (true) {
            $response instanceof Response => $response->json() ?? [],
            $response instanceof ApiResponse => $response->data,
            is_object($response) && method_exists($response, 'toArray') => $response->toArray(),
            is_array($response) => $response,
            default => [],
        };
        $data = is_array($data) ? $data : [];
        $usage = $data['usage'] ?? [];
        $usage = is_array($usage) ? $usage : [];
        $input = $this->tokens($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null);
        $output = $this->tokens($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null);
        $total = $this->tokens($usage['total_tokens'] ?? null) ??
            ($input !== null && $output !== null ? $input + $output : null);
        $failed = $response instanceof Response && ! $response->successful();
        $status = $failed ? 'failed' : match ($data['status'] ?? null) {
            'outcome_unknown', 'unknown' => 'unknown',
            'pending', 'running', 'queued', 'in_progress' => 'pending',
            'failed' => 'failed',
            default => 'completed',
        };
        $database->table('ai_governance_usage')->where('id', $id)->update([
            'status' => $status,
            'model' => is_string($data['model'] ?? null) ? Str::limit($data['model'], 190, '') : $model,
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'http_status' => $response instanceof Response ? $response->status() : null,
            'correlation_id' => $response instanceof ApiResponse ? $response->correlationId : null,
            'updated_at' => now('UTC'),
        ]);

        return $response;
    }

    private function tokens(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
