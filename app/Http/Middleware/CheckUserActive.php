<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
                'code' => 'ACCOUNT_DEACTIVATED'
            ], 403);
        }

        return $next($request);
    }
}
