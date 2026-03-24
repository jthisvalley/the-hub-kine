<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use App\Models\PersonalAccessToken;
use App\Models\SecurityEvent;
use App\Services\SecurityEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SecurityController extends Controller
{
    protected $securityEventService;

    public function __construct(SecurityEventService $securityEventService)
    {
        $this->securityEventService = $securityEventService;
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols()
            ],
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect',
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        $fingerprint = $request->header('X-Device-Fingerprint');
        $device = null;
        if ($fingerprint) {
            $device = UserDevice::where('user_id', $user->id)
                ->where('device_fingerprint', $fingerprint)
                ->first();
        }

        $this->securityEventService->log(
            $user,
            \App\Models\SecurityEvent::TYPE_PASSWORD_CHANGE,
            'Mot de passe modifié',
            $request,
            $device,
            [],
            'success'
        );

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès',
        ]);
    }

    /**
     * Get active sessions (grouped by device)
     */
    public function getSessions(Request $request)
    {
        $user = Auth::user();

        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 10);

        $devicesQuery = UserDevice::where('user_id', $user->id)
            ->with(['tokens' => function ($query) {
                $query->latest('last_used_at');
            }])
            ->orderBy('last_used_at', 'desc');

        $totalDevices = $devicesQuery->count();
        $devices = $devicesQuery->paginate($perPage, ['*'], 'page', $page);

        $formattedDevices = $devices->map(function ($device) use ($request) {
            $latestToken = $device->tokens->first();
            $isCurrent = false;

            $currentToken = $request->user()->currentAccessToken();
            if ($currentToken && $currentToken->device_id === $device->id) {
                $isCurrent = true;
            }

            return [
                'id' => $device->id,
                'device_name' => $device->device_name ?? 'Appareil inconnu',
                'device_type' => $device->device_type ?? 'desktop',
                'os' => $device->os ?? 'Inconnu',
                'browser' => $device->browser ?? 'Inconnu',
                'last_active' => $latestToken?->last_used_at?->toISOString() ?? $device->last_used_at?->toISOString(),
                'first_used' => $device->first_used_at?->toISOString(),
                'location' => $device->location ?? 'Maroc',
                'ip_address' => $device->ip_address ?? 'Inconnu',
                'is_current' => $isCurrent,
                'trusted' => $device->is_trusted ?? false,
                'active_sessions' => $device->tokens->count(),
                'fingerprint' => substr($device->device_fingerprint, 0, 8) . '...',
            ];
        })->values();

        $securityEvents = $this->securityEventService->getUserEvents($user, $perPage, $page);

        return response()->json([
            'success' => true,
            'data' => [
                'devices' => $formattedDevices,
                'total_devices' => $totalDevices,
                'security_events' => $securityEvents->items(),
                'security_events_pagination' => [
                    'current_page' => $securityEvents->currentPage(),
                    'per_page' => $securityEvents->perPage(),
                    'total' => $securityEvents->total(),
                    'last_page' => $securityEvents->lastPage(),
                    'from' => $securityEvents->firstItem(),
                    'to' => $securityEvents->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Revoke a session
     */
    public function revokeSession(Request $request, $tokenId)
    {
        $user = Auth::user();

        $token = PersonalAccessToken::where('id', $tokenId)
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Session non trouvée',
            ], 404);
        }

        if ($token->id === $request->user()->currentAccessToken()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de déconnecter la session actuelle',
            ], 400);
        }

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Session déconnectée avec succès',
        ]);
    }

    /**
     * Revoke all other sessions for a device
     */
    public function revokeDeviceSessions(Request $request, $deviceId)
    {
        $user = Auth::user();

        $device = UserDevice::where('id', $deviceId)
            ->where('user_id', $user->id)
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Appareil non trouvé',
            ], 404);
        }

        $currentTokenId = $request->user()->currentAccessToken()->id;

        $deleted = $device->tokens()
            ->where('id', '!=', $currentTokenId)
            ->delete();

        $this->securityEventService->log(
            $user,
            SecurityEvent::TYPE_DEVICE_REMOVED,
            "Sessions déconnectées pour l'appareil: {$device->device_name}",
            $request,
            $device,
            ['sessions_deleted' => $deleted],
            'info'
        );

        return response()->json([
            'success' => true,
            'message' => 'Toutes les sessions de cet appareil ont été déconnectées',
            'data' => [
                'sessions_deleted' => $deleted
            ]
        ]);
    }

    /**
     * Update device trust status
     */
    public function updateDeviceTrust(Request $request, $deviceId)
    {
        $validator = Validator::make($request->all(), [
            'trusted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $device = UserDevice::where('id', $deviceId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Appareil non trouvé',
            ], 404);
        }

        $oldTrust = $device->is_trusted;
        $device->is_trusted = $request->trusted;
        $device->save();

        $eventType = $request->trusted
            ? \App\Models\SecurityEvent::TYPE_DEVICE_TRUSTED
            : \App\Models\SecurityEvent::TYPE_DEVICE_UNTRUSTED;

        $this->securityEventService->log(
            Auth::user(),
            $eventType,
            $request->trusted ? 'Appareil approuvé' : 'Appareil désapprouvé',
            $request,
            $device,
            ['device_name' => $device->device_name],
            'info'
        );

        return response()->json([
            'success' => true,
            'message' => $request->trusted ? 'Appareil approuvé' : 'Appareil désapprouvé',
            'data' => [
                'trusted' => $device->is_trusted,
            ],
        ]);
    }

    /**
     * Update device settings
     */
    public function updateDeviceSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'new_device_notifications' => 'sometimes|boolean',
            'auto_logout' => 'sometimes|boolean',
            'manual_approval' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        $settings = $user->settings ?? new \App\Models\UserSettings(['user_id' => $user->id]);
        $deviceSettings = $settings->device_settings ?? [];

        if ($request->has('new_device_notifications')) {
            $deviceSettings['new_device_notifications'] = $request->new_device_notifications;
        }
        if ($request->has('auto_logout')) {
            $deviceSettings['auto_logout'] = $request->auto_logout;
        }
        if ($request->has('manual_approval')) {
            $deviceSettings['manual_approval'] = $request->manual_approval;
        }

        $settings->device_settings = $deviceSettings;
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Paramètres des appareils mis à jour',
        ]);
    }

    /**
     * Get security history with pagination
     */
    public function getSecurityHistory(Request $request)
    {
        $user = Auth::user();

        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 10);

        $events = $this->securityEventService->getUserEvents($user, $perPage, $page);

        return response()->json([
            'success' => true,
            'data' => $events->items(),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
        ]);
    }
}
