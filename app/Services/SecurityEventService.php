<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

class SecurityEventService
{
    /**
     * Log a security event
     */
    public function log(
        User $user,
        string $eventType,
        string $description,
        Request $request = null,
        UserDevice $device = null,
        array $metadata = [],
        string $status = 'info'
    ): SecurityEvent {
        $ip = $request?->ip() ?? request()->ip();
        $userAgent = $request?->userAgent() ?? request()->userAgent();

        // Get location from IP
        $location = $this->getLocationFromIp($ip);

        // If device not provided, try to find it
        if (!$device && $request) {
            $fingerprint = $request->header('X-Device-Fingerprint');
            if ($fingerprint) {
                $device = UserDevice::where('user_id', $user->id)
                    ->where('device_fingerprint', $fingerprint)
                    ->first();
            }
        }

        return SecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'description' => $description,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'location' => $location,
            'device_id' => $device?->id,
            'metadata' => $metadata,
            'status' => $status,
        ]);
    }

    /**
     * Get location from IP
     */
    protected function getLocationFromIp(?string $ip): string
    {
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
            return 'Localhost';
        }

        try {
            $response = file_get_contents("http://ip-api.com/json/{$ip}");
            $data = json_decode($response, true);

            if ($data && $data['status'] === 'success') {
                $city = $data['city'] ?? '';
                $country = $data['country'] ?? 'Morocco';

                if ($country === 'Morocco') {
                    return $city ?: 'Maroc';
                }

                return $city ? "{$city}, {$country}" : $country;
            }
        } catch (\Exception $e) {
            \Log::error('Location lookup failed: ' . $e->getMessage());
        }

        return 'Maroc'; // Default to Morocco
    }

    /**
     * Get security events for a user with pagination
     */
    public function getUserEvents(User $user, int $perPage = 10, int $page = 1)
    {
        return SecurityEvent::where('user_id', $user->id)
            ->with('device')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get recent security events for a user (limit)
     */
    public function getRecentEvents(User $user, int $limit = 10)
    {
        return SecurityEvent::where('user_id', $user->id)
            ->with('device')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
