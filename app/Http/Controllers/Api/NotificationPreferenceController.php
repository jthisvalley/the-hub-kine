<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class NotificationPreferenceController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get user's notification preferences
     */
    public function show()
    {
        $user = Auth::user();

        $preferences = $user->notificationPreference;

        if (!$preferences) {
            $preferences = NotificationPreference::create([
                'user_id' => $user->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $preferences,
        ]);
    }

    /**
     * Update notification preferences
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            // Email settings
            'email_notifications' => 'sometimes|boolean',
            'appointment_reminders' => 'sometimes|boolean',
            'appointment_cancellations' => 'sometimes|boolean',
            'appointment_confirmations' => 'sometimes|boolean',
            'exercise_reminders' => 'sometimes|boolean',
            'marketing_emails' => 'sometimes|boolean',

            // SMS settings
            'sms_notifications' => 'sometimes|boolean',
            'phone_number' => 'nullable|string|max:20',

            // WhatsApp settings
            'whatsapp_notifications' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:20',

            // Push settings
            'push_notifications' => 'sometimes|boolean',

            // Schedule settings
            'quiet_hours_enabled' => 'sometimes|boolean',
            'quiet_hours_start' => 'nullable|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/',
            'quiet_hours_end' => 'nullable|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/',
            'max_daily_notifications' => 'sometimes|integer|min:5|max:100',
            'notification_priority' => 'sometimes|in:high,medium,low',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $preferences = $user->notificationPreference;

        if (!$preferences) {
            $preferences = new NotificationPreference(['user_id' => $user->id]);
        }

        $preferences->fill($request->only([
            'email_notifications',
            'appointment_reminders',
            'appointment_cancellations',
            'appointment_confirmations',
            'exercise_reminders',
            'marketing_emails',
            'sms_notifications',
            'phone_number',
            'whatsapp_notifications',
            'whatsapp_number',
            'push_notifications',
            'quiet_hours_enabled',
            'quiet_hours_start',
            'quiet_hours_end',
            'max_daily_notifications',
            'notification_priority',
        ]));

        $preferences->save();

        return response()->json([
            'success' => true,
            'message' => 'Préférences de notification mises à jour',
            'data' => $preferences->fresh(),
        ]);
    }

    /**
     * Send test notification
     */
    public function test(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:email,sms,push',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->type;
        $title = 'Notification de test';
        $message = 'Ceci est une notification de test de LeHubKiné.';

        try {
            switch ($type) {
                case 'email':
                    $this->notificationService->sendEmail($user, $title, $message, ['test' => true]);
                    break;

                case 'sms':
                    $this->notificationService->sendSMS($user, 'Test LeHubKiné: Ceci est un SMS de test.', ['test' => true]);
                    break;

                case 'push':
                    $this->notificationService->sendPushNotification(
                        $user,
                        'test.notification',
                        $title,
                        $message,
                        ['test' => true],
                        null,
                        'low'
                    );
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => "Notification {$type} de test envoyée avec succès",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get notification statistics
     */
    public function statistics()
    {
        $user = Auth::user();

        $today = now()->format('Y-m-d');
        $key = 'notification_daily_count_' . $user->id . '_' . $today;
        $todayCount = (int) Cache::get($key, 0);

        $preferences = $user->notificationPreference;
        $maxDaily = $preferences?->max_daily_notifications ?? 20;

        return response()->json([
            'success' => true,
            'data' => [
                'today_count' => $todayCount,
                'max_daily' => $maxDaily,
                'remaining' => max(0, $maxDaily - $todayCount),
                'quiet_hours_active' => $preferences?->isQuietHours() ?? false,
            ],
        ]);
    }
}
