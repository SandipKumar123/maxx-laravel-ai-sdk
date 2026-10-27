<?php

namespace Modules\AiGovernance\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->currentAccessToken() instanceof PersonalAccessToken,
            401, 'A valid Sanctum personal access token is required.');

        return $next($request);
    }
}
