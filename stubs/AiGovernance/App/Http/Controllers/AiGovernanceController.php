<?php

namespace Modules\AiGovernance\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Maxx\Ai\Http\Settings;
use Modules\AiGovernance\Services\GovernanceAccess;
use Modules\AiGovernance\Services\GovernanceReport;
use Modules\AiGovernance\Services\RegionalDatabase;
use Modules\AiGovernance\Services\SdkConnection;
use Modules\AiGovernance\Services\UsageIdentity;
use Modules\AiGovernance\Services\ExecutionSettings;

class AiGovernanceController extends Controller
{
    public function index(Request $request)
    {
        return $this->show($request, 'overview');
    }

    public function analytics(Request $request)
    {
        return $this->show($request, 'analytics');
    }

    public function requests(Request $request)
    {
        return $this->show($request, 'requests');
    }

    public function sdk(Request $request)
    {
        return $this->show($request, 'sdk');
    }

    private function show(Request $request, string $page)
    {
        $data = app(GovernanceReport::class)->data($request, $page, $request->expectsJson());
        if ($request->expectsJson()) {
            unset($data['page']);
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('ai-governance::index', $data)->header('Cache-Control', 'private, no-store');
    }

    public function checkConnection(Request $request)
    {
        $identity = UsageIdentity::current();
        abort_unless(GovernanceAccess::canManageSdk($request->user()), 403, 'Regional administrator permission is required.');
        if ($request->exists('base_url') || $request->exists('access_token')) {
            $validator = Validator::make($request->all(), [
                'base_url' => ['required', 'string', 'max:2048', 'url'],
                'access_token' => ['required', 'string', 'max:128', 'regex:/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/'],
            ] + ExecutionSettings::rules(false));
            $validator->after(function ($validator) use ($request): void {
                try {
                    new Settings(
                        (string) $request->input('base_url'),
                        app()->environment(),
                        (bool) config('maxx-ai.allow_provisional'),
                    );
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('base_url', 'Use an HTTPS origin; HTTP is allowed only for a loopback development server.');
                }
            });
            if ($validator->fails()) {
                $request->session()->forget('ai_governance.sdk_verified');
                if ($request->expectsJson()) {
                    return response()->json(['state' => 'configuration_required', 'errors' => $validator->errors()], 422);
                }

                return redirect()->route('ai-governance.sdk')
                    ->withErrors($validator)->withInput($request->except('access_token'));
            }

            $data = $validator->validated();
            $result = app(SdkConnection::class)->verifyCredentials(
                rtrim($data['base_url'], '/'),
                $data['access_token'],
            );
            $result['checked_at'] = now('UTC')->toIso8601String();
            if ($result['state'] === 'connected') {
                $request->session()->put('ai_governance.sdk_verified', [
                    'fingerprint' => $this->verificationFingerprint($data['base_url'], $data['access_token'], $identity, $request),
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ]);
            } else {
                $request->session()->forget('ai_governance.sdk_verified');
            }
            if ($request->expectsJson()) {
                return response()->json($result, $result['state'] === 'connected' ? 200 : 503)
                    ->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('ai-governance.sdk')
                ->with('ai_sdk_check', $result + ['tenant_id' => $identity->tenantId, 'region' => $identity->region])
                ->withInput($request->except('access_token'));
        }

        $result = app(SdkConnection::class)->check();
        $result['checked_at'] = now('UTC')->toIso8601String();
        if ($request->expectsJson()) {
            return response()->json($result, $result['state'] === 'connected' ? 200 : 503)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('ai-governance.sdk')->with('ai_sdk_check', $result + [
            'tenant_id' => $identity->tenantId, 'region' => $identity->region,
        ]);
    }

    public function saveConnection(Request $request)
    {
        abort_unless(GovernanceAccess::canManageSdk($request->user()), 403, 'Regional administrator permission is required.');
        $identity = UsageIdentity::current();
        RegionalDatabase::connection($identity->region);
        $validator = Validator::make($request->all(), [
            'base_url' => ['required', 'string', 'max:2048', 'url'],
            'access_token' => ['required', 'string', 'max:128', 'regex:/\Amaxx_sdk_[A-Za-z0-9_-]{43}\z/'],
        ] + ExecutionSettings::rules());
        $validator->after(function ($validator) use ($request, $identity): void {
            try {
                new Settings((string) $request->input('base_url'), 'local', true);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('base_url', 'Use an HTTPS origin; HTTP is allowed only for a loopback development server.');
            }
            $verified = $request->session()->get('ai_governance.sdk_verified');
            $fingerprint = $this->verificationFingerprint(
                rtrim((string) $request->input('base_url'), '/'),
                (string) $request->input('access_token'),
                $identity,
                $request,
            );
            if (! is_array($verified) || ($verified['expires_at'] ?? 0) < now()->timestamp
                || ! is_string($verified['fingerprint'] ?? null)
                || ! hash_equals($verified['fingerprint'], $fingerprint)) {
                $validator->errors()->add('access_token', 'Verify this URL and credential with Universal AI before saving.');
            }
        });
        if ($validator->fails()) {
            return redirect()->route('ai-governance.sdk')->withErrors($validator)->withInput($request->except('access_token'));
        }

        $data = $validator->validated();
        app(SdkConnection::class)->saveVerified($data);
        $request->session()->forget('ai_governance.sdk_verified');

        return redirect()->route('ai-governance.sdk')->with('status', 'Verified Universal AI connection saved. No AI generation was performed.');
    }

    private function verificationFingerprint(string $baseUrl, string $token, UsageIdentity $identity, Request $request): string
    {
        return hash_hmac('sha256', implode("\0", [
            rtrim($baseUrl, '/'),
            $token,
            (string) $identity->tenantId,
            $identity->region,
            (string) $request->input('resource_id'),
            (string) $request->input('model_id'),
            (string) $request->input('execution_region'),
            (string) $request->input('executor_subject'),
            (string) $identity->userId,
        ]), (string) config('app.key'));
    }
}
