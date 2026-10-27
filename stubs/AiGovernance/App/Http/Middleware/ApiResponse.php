<?php

namespace Modules\AiGovernance\App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        try {
            abort_unless($request->bearerToken(), 401, 'A Sanctum bearer token is required.');
            $response = $next($request);
        } catch (AuthenticationException) {
            $response = response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        } catch (ValidationException $error) {
            $response = response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $error->errors()], 422);
        } catch (HttpExceptionInterface $error) {
            $response = response()->json([
                'success' => false,
                'message' => $error->getMessage() ?: (Response::$statusTexts[$error->getStatusCode()] ?? 'Request failed.'),
            ], $error->getStatusCode(), $error->getHeaders());
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
