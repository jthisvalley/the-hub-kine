<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiVersionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $version
     */
    public function handle(Request $request, Closure $next, string $version): Response
    {
        $validVersions = ['v1', 'v2', 'v3'];

        if (!in_array($version, $validVersions)) {
            return response()->json([
                'error' => 'Invalid API version',
                'message' => 'The requested API version is not supported.',
                'supported_versions' => $validVersions,
                'current_version' => 'v1',
            ], 400);
        }

        $request->headers->set('X-API-Version', $version);

        $response = $next($request);

        $response->headers->set('X-API-Version', $version);
        $response->headers->set('X-API-Latest-Version', 'v1');
        $response->headers->set('X-API-Deprecation-Notice', 'N/A');

        return $response;
    }
}
