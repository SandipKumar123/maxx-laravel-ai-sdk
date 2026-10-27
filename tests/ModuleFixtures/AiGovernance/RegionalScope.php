<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests\ModuleFixtures\AiGovernance;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RegionalScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $region = config('app.jurisdiction');
        abort_unless($user && (int) $user->tenant_id > 0, 403);
        foreach (['tenant_id' => $user->tenant_id, 'region' => $region] as $key => $expected) {
            $candidate = $request->header('X-'.str_replace('_', '-', $key)) ?? $request->input($key);
            abort_if($candidate !== null && (string) $candidate !== (string) $expected, 403);
        }
        app(\App\Support\ScopeContext::class)->establish((int) $user->tenant_id, null, $region);

        return $next($request);
    }
}
