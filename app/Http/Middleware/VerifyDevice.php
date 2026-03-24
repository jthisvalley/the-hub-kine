<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\UserDevice;
use Symfony\Component\HttpFoundation\Response;

class VerifyDevice
{

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/login') || $request->is('api/v1/register')) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }

        $deviceFingerprint = $request->header('X-Device-Fingerprint');

        if (!$deviceFingerprint) {
            return response()->json([
                'error' => 'Device identification required',
                'message' => 'Device fingerprint header is missing'
            ], 400);
        }

        $devices = UserDevice::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();
        $matchedDevice = null;
        foreach ($devices as $device) {
            if ($device->device_fingerprint === $deviceFingerprint) {
                $matchedDevice = $device;
                break;
            }
        }

        if (!$matchedDevice) {
            return response()->json([
                'error' => 'Device not authorized',
                'message' => 'This device is not authorized. Please login again.'
            ], 403);
        }

        $matchedDevice->update([
            'last_used_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        return $next($request);
    }

}
