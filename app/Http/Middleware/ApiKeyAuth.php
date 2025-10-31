<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key') ?? $request->query('api_key');

        if (empty($apiKey)) {
            return response()->json([
                'code' => 'API_KEY_MISSING',
                'result' => [
                    'data' => null,
                    'error_message' => 'API key is required. Please provide an API key via X-API-Key header.',
                ],
            ], 401);
        }

        $validApiKeys = config('api.keys', []);

        if (!in_array($apiKey, $validApiKeys)) {
            return response()->json([
                'code' => 'API_KEY_INVALID',
                'result' => [
                    'data' => null,
                    'error_message' => 'The provided API key is not valid.',
                ],
            ], 401);
        }

        return $next($request);
    }
}