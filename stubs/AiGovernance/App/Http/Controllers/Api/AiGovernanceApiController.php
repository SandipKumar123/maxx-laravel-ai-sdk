<?php

namespace Modules\AiGovernance\App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Maxx\Ai\Exceptions\ApiException;
use Maxx\Ai\Exceptions\ProtocolException;
use Maxx\Ai\Exceptions\TransportException;
use Maxx\Ai\Http\Settings;
use Modules\AiGovernance\Exceptions\AiFeatureUnavailable;
use Modules\AiGovernance\Services\ExecutionSettings;
use Modules\AiGovernance\Services\GovernanceAccess;
use Modules\AiGovernance\Services\GovernanceReport;
use Modules\AiGovernance\Services\HrmsAiClient;
use Modules\AiGovernance\Services\RegionalDatabase;
use Modules\AiGovernance\Services\SdkConnection;
use Modules\AiGovernance\Services\UsageIdentity;

final class AiGovernanceApiController extends Controller
{
    public function overview(Request $request, GovernanceReport $report): JsonResponse
    {
        return $this->report($request, $report, 'overview', ['summary']);
    }

    public function analytics(Request $request, GovernanceReport $report): JsonResponse
    {
        return $this->report($request, $report, 'analytics', ['summary', 'byModule', 'byUser', 'modules', 'users']);
    }

    public function requests(Request $request, GovernanceReport $report): JsonResponse
    {
        return $this->report($request, $report, 'requests', ['records', 'modules', 'users']);
    }

    public function connection(Request $request, GovernanceReport $report): JsonResponse
    {
        return $this->report($request, $report, 'sdk', ['sdk', 'sdkConfiguration', 'canManageSdk']);
    }

    public function checkConnection(Request $request, SdkConnection $connection): JsonResponse
    {
        $this->authorizeManager($request);
        if ($request->hasAny(['base_url', 'access_token', 'resource_id', 'model_id', 'execution_region', 'executor_subject'])) {
            $data = $this->connectionData($request);
            $result = $connection->verifyCredentials($data['base_url'], $data['access_token']);
        } else {
            $result = $connection->check();
        }

        return $this->connectionResult($result);
    }

    public function saveConnection(Request $request, SdkConnection $connection): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $this->connectionData($request);
        $result = $connection->verifyCredentials($data['base_url'], $data['access_token']);
        if ($result['state'] !== 'connected') {
            return $this->connectionResult($result);
        }
        $connection->saveVerified($data);

        return response()->json(['success' => true, 'data' => [
            'message' => 'Verified Universal AI connection saved. No AI generation was performed.',
            'configuration' => $connection->configuration(),
        ]]);
    }

    public function execution(Request $request, string $requestId, HrmsAiClient $client): JsonResponse
    {
        UsageIdentity::current();
        abort_unless(GovernanceAccess::allows($request->user()), 403, 'AI Governance permission is required.');
        try {
            return response()->json(['success' => true, 'data' => $client->status($requestId)]);
        } catch (AiFeatureUnavailable $error) {
            return response()->json(['success' => false] + $error->payload(), 503);
        } catch (ApiException|ProtocolException|TransportException $error) {
            return response()->json(['success' => false, 'message' => 'Execution status could not be retrieved from the AI backend.',
                'correlation_id' => $error->correlationId], 502);
        } catch (\UnexpectedValueException $error) {
            report($error);

            return response()->json(['success' => false, 'message' => 'The AI backend returned an invalid execution status.'], 502);
        }
    }

    private function report(Request $request, GovernanceReport $report, string $page, array $keys): JsonResponse
    {
        $data = $report->data($request, $page);

        return response()->json(['success' => true, 'data' => array_intersect_key($data, array_flip([...$keys, 'filters', 'identity']))]);
    }

    private function authorizeManager(Request $request): void
    {
        $identity = UsageIdentity::current();
        abort_unless(GovernanceAccess::canManageSdk($request->user()), 403, 'Regional administrator permission is required.');
        RegionalDatabase::connection($identity->region);
    }

    private function connectionData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'base_url' => ['required', 'string', 'max:2048', 'url'],
            'access_token' => ['required', 'string', 'max:128', 'regex:/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/'],
        ] + ExecutionSettings::rules());
        $validator->after(function ($validator) use ($request): void {
            if ($validator->errors()->has('base_url')) {
                return;
            }
            try {
                new Settings($request->input('base_url'), 'local', true);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('base_url', 'Use an HTTPS origin; HTTP is allowed only for a loopback development server.');
            }
        });
        $data = $validator->validate();
        $data['base_url'] = rtrim($data['base_url'], '/');

        return $data;
    }

    private function connectionResult(array $result): JsonResponse
    {
        $status = match ($result['state']) {
            'connected' => 200,
            'access_denied', 'blocked' => 403,
            'disabled', 'configuration_required' => 422,
            'unavailable' => 503,
            default => 502,
        };

        return response()->json(['success' => $status === 200,
            'data' => $result + ['checked_at' => now('UTC')->toIso8601String()]], $status);
    }
}
