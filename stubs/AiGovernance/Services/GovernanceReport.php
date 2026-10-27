<?php

namespace Modules\AiGovernance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GovernanceReport
{
    public function data(Request $request, string $page, bool $all = false): array
    {
        $identity = UsageIdentity::current();
        abort_unless(GovernanceAccess::allows($request->user()), 403, 'AI Governance permission is required.');
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'module' => ['nullable', 'string', 'max:80'],
            'provider' => ['nullable', 'in:openai,maxx-ai'],
            'status' => ['nullable', 'in:pending,completed,failed,unknown'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'users_page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $database = RegionalDatabase::connection($identity->region);
        $base = $database->table('ai_governance_usage')->where('tenant_id', $identity->tenantId)->where('region', $identity->region);
        $query = clone $base;
        foreach (['user_id', 'module', 'provider', 'status'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $query->where($key, $filters[$key]);
            }
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->format('Y-m-d').' 00:00:00');
        }

        $summary = null;
        $byModule = collect();
        $byUser = null;
        $records = null;
        $modules = collect();
        $users = collect();
        $sdk = null;
        $sdkConfiguration = null;
        $canManageSdk = GovernanceAccess::canManageSdk($request->user());
        if ($all || in_array($page, ['overview', 'analytics'], true)) {
            $summary = (clone $query)->selectRaw("COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens,
                COALESCE(SUM(CASE WHEN total_tokens IS NULL THEN 1 ELSE 0 END), 0) AS unreported,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(CASE WHEN status IN ('pending', 'unknown') THEN 1 ELSE 0 END), 0) AS unresolved")->first();
        }
        if ($all || $page === 'analytics') {
            $byModule = (clone $query)->select('module')->selectRaw('COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens')
                ->groupBy('module')->orderByDesc('requests')->orderBy('module')->get();
            $byUser = (clone $query)->select('user_id')->selectRaw('COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS tokens')
                ->groupBy('user_id')->orderByDesc('requests')->orderBy('user_id')->paginate(10, ['*'], 'users_page')->withQueryString();
        }
        if ($all || $page === 'requests') {
            $records = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        }
        if ($all || in_array($page, ['analytics', 'requests'], true)) {
            $modules = (clone $base)->distinct()->orderBy('module')->pluck('module');
            $userIds = (clone $base)->distinct()->pluck('user_id');
            $users = $database->table('users')->where('tenant_id', $identity->tenantId)->whereIn('id', $userIds)
                ->orderBy('name')->pluck('name', 'id');
        }
        if ($all || $page === 'sdk') {
            $sdk = app(SdkConnection::class)->status();
            $sdkConfiguration = app(SdkConnection::class)->configuration();
        }

        return compact('summary', 'byModule', 'byUser', 'records', 'modules', 'users', 'filters',
            'identity', 'sdk', 'sdkConfiguration', 'canManageSdk', 'page');
    }
}
