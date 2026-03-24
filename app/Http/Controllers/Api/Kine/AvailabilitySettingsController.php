<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\AvailabilitySetting;
use App\Models\AppointmentTypeSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AvailabilitySettingsController extends Controller
{
    /**
     * Get availability settings for the authenticated kine
     */
    public function index()
    {
        $user = Auth::user();

        $availabilitySettings = AvailabilitySetting::firstOrCreate(
            ['kine_id' => $user->id],
            [
                'working_days' => [
                    'monday' => true,
                    'tuesday' => true,
                    'wednesday' => true,
                    'thursday' => true,
                    'friday' => true,
                    'saturday' => false,
                    'sunday' => false,
                ],
                'work_start' => '08:00:00',
                'work_end' => '18:00:00',
                'has_lunch_break' => true,
                'lunch_start' => '12:00:00',
                'lunch_end' => '14:00:00',
                'default_duration' => 30,
                'buffer_time' => 15,
                'max_advance_booking' => 30,
                'min_advance_booking' => 2,
                'email_reminders' => true,
                'sms_reminders' => true,
                'reminder_time' => 24,
            ]
        );

        $appointmentTypes = AppointmentTypeSetting::where('kine_id', $user->id)->get();

        // Create default appointment types if they don't exist
        $defaultTypes = ['consultation', 'follow_up', 'emergency', 'initial_evaluation', 'rehabilitation'];

        foreach ($defaultTypes as $type) {
            AppointmentTypeSetting::firstOrCreate(
                [
                    'kine_id' => $user->id,
                    'type' => $type,
                ],
                [
                    'default_duration' => 30,
                    'default_price' => 50,
                    'is_active' => true,
                ]
            );
        }

        // Refresh to get all types
        $appointmentTypes = AppointmentTypeSetting::where('kine_id', $user->id)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'working_days' => $availabilitySettings->working_days,
                'working_hours' => [
                    'start' => $availabilitySettings->work_start->format('H:i'),
                    'end' => $availabilitySettings->work_end->format('H:i'),
                    'lunch_start' => $availabilitySettings->lunch_start?->format('H:i'),
                    'lunch_end' => $availabilitySettings->lunch_end?->format('H:i'),
                    'has_lunch_break' => $availabilitySettings->has_lunch_break,
                ],
                'session_settings' => [
                    'default_duration' => $availabilitySettings->default_duration,
                    'buffer_time' => $availabilitySettings->buffer_time,
                    'max_advance_booking' => $availabilitySettings->max_advance_booking,
                    'min_advance_booking' => $availabilitySettings->min_advance_booking,
                ],
                'notification_settings' => [
                    'email_reminders' => $availabilitySettings->email_reminders,
                    'sms_reminders' => $availabilitySettings->sms_reminders,
                    'reminder_time' => $availabilitySettings->reminder_time,
                ],
                'appointment_types' => $appointmentTypes->map(function ($type) {
                    return [
                        'id' => $type->id,
                        'type' => $type->type,
                        'default_duration' => $type->default_duration,
                        'default_price' => (float) $type->default_price,
                        'is_active' => $type->is_active,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update availability settings
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'working_days' => 'required|array',
            'working_hours' => 'required|array',
            'working_hours.start' => 'required|string',
            'working_hours.end' => 'required|string',
            'working_hours.lunch_start' => 'nullable|string',
            'working_hours.lunch_end' => 'nullable|string',
            'working_hours.has_lunch_break' => 'required|boolean',
            'session_settings' => 'required|array',
            'session_settings.default_duration' => 'required|integer|min:15|max:120',
            'session_settings.buffer_time' => 'required|integer|min:0|max:60',
            'session_settings.max_advance_booking' => 'required|integer|min:1|max:365',
            'session_settings.min_advance_booking' => 'required|integer|min:1|max:72',
            'notification_settings' => 'required|array',
            'notification_settings.email_reminders' => 'required|boolean',
            'notification_settings.sms_reminders' => 'required|boolean',
            'notification_settings.reminder_time' => 'required|integer|min:1|max:168',
            'appointment_types' => 'required|array',
            'appointment_types.*.type' => 'required|string',
            'appointment_types.*.default_duration' => 'required|integer|min:15|max:120',
            'appointment_types.*.default_price' => 'required|numeric|min:0',
            'appointment_types.*.is_active' => 'required|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Update or create availability settings
            $availabilitySettings = AvailabilitySetting::updateOrCreate(
                ['kine_id' => $user->id],
                [
                    'working_days' => $validated['working_days'],
                    'work_start' => $validated['working_hours']['start'],
                    'work_end' => $validated['working_hours']['end'],
                    'has_lunch_break' => $validated['working_hours']['has_lunch_break'],
                    'lunch_start' => $validated['working_hours']['lunch_start'] ?? null,
                    'lunch_end' => $validated['working_hours']['lunch_end'] ?? null,
                    'default_duration' => $validated['session_settings']['default_duration'],
                    'buffer_time' => $validated['session_settings']['buffer_time'],
                    'max_advance_booking' => $validated['session_settings']['max_advance_booking'],
                    'min_advance_booking' => $validated['session_settings']['min_advance_booking'],
                    'email_reminders' => $validated['notification_settings']['email_reminders'],
                    'sms_reminders' => $validated['notification_settings']['sms_reminders'],
                    'reminder_time' => $validated['notification_settings']['reminder_time'],
                ]
            );

            // Update appointment type settings
            foreach ($validated['appointment_types'] as $typeData) {
                AppointmentTypeSetting::updateOrCreate(
                    [
                        'kine_id' => $user->id,
                        'type' => $typeData['type'],
                    ],
                    [
                        'default_duration' => $typeData['default_duration'],
                        'default_price' => $typeData['default_price'],
                        'is_active' => $typeData['is_active'],
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paramètres mis à jour avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des paramètres',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
