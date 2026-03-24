<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentTypeSetting;
use App\Models\AvailabilitySetting;
use App\Models\CancellationReason;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use App\Events\AppointmentStatusChanged;
use App\Events\AppointmentReminder;
use App\Models\AppointmentCancellationReason;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PatientCalendarController extends Controller
{
    /**
     * Get patient's appointments
     */
    public function appointments(Request $request)
    {
        $user = Auth::user();

        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', '');
        $status = $request->get('status', 'all');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $kineId = $request->get('kine_id');

        $query = Appointment::where('patient_id', $user->id)
            ->with([
                'slot.kine:id,first_name,last_name,email,avatar_url,phone',
                'slot.kine.kineProfile:user_id,specialty,bio,years_of_experience',
                'cancellationReasons'
            ])
            ->select([
                'id', 'patient_id', 'slot_id', 'type', 'status', 'notes',
                'created_at', 'location', 'is_online', 'video_link', 'price'
            ])
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('type', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('slot.kine', function ($q) use ($search) {
                      $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($kineId) {
            $query->whereHas('slot', function ($q) use ($kineId) {
                $q->where('kine_id', $kineId);
            });
        }

        if ($startDate && $endDate) {
            $query->whereHas('slot', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_time', [$startDate, $endDate]);
            });
        }

        $appointments = $query->paginate($perPage);

        return AppointmentResource::collection($appointments);
    }

    /**
     * Get calendar events for patient
     */
    public function events(Request $request)
    {
        $user = Auth::user();

        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
        $kineId = $request->get('kine_id');

        $query = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->with([
                'slot.kine:id,first_name,last_name,email,avatar_url,phone',
                'slot.kine.kineProfile:user_id,specialty,bio,years_of_experience',
                'reminders:id,appointment_id,reminder_type,reminder_hours_before,sent_at,status',
                'cancellationReasons',
                'report.documents'
            ])
            ->select([
                'id', 'patient_id', 'slot_id', 'type', 'notes', 'status', 'location',
                'is_online', 'video_link', 'price', 'created_at', 'updated_at'
            ])
            ->orderBy('created_at', 'desc');

        if ($kineId) {
            $query->whereHas('slot', function ($q) use ($kineId) {
                $q->where('kine_id', $kineId);
            });
        }

        $appointments = $query->get();

        $events = $appointments->map(function ($appointment) {
            return $this->formatEvent($appointment);
        });

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    private function formatEvent($appointment)
    {
        $colorName = match($appointment->type) {
            'consultation' => 'blue',
            'follow_up' => 'green',
            'emergency' => 'red',
            'initial_evaluation' => 'purple',
            'rehabilitation' => 'yellow',
            default => 'gray',
        };

        $kineName = $appointment->slot->kine
            ? "{$appointment->slot->kine->first_name} {$appointment->slot->kine->last_name}"
            : 'Kinésithérapeute';

        $specialty = $appointment->slot->kine->kineProfile->specialty ?? 'Kinésithérapeute général';

        // Format report data if exists
        $reportData = null;
        if ($appointment->report) {
            $reportData = [
                'id' => $appointment->report->id,
                'notes' => $appointment->report->notes,
                'created_at' => $appointment->report->created_at->toISOString(),
                'updated_at' => $appointment->report->updated_at->toISOString(),
                'documents' => $appointment->report->documents->map(function ($document) {
                    return [
                        'id' => $document->id,
                        'filename' => $document->filename,
                        'file_path' => $document->file_path,
                        'file_size' => $document->file_size,
                        'mime_type' => $document->mime_type,
                        'created_at' => $document->created_at->toISOString(),
                    ];
                })->toArray(),
            ];
        }

        return [
            'id' => $appointment->id,
            'title' => $appointment->slot->kine
                ? "Rendez-vous avec {$kineName}"
                : "Rendez-vous",
            'description' => $appointment->notes,
            'startDate' => $appointment->slot->start_time->toISOString(),
            'endDate' => $appointment->slot->end_time->toISOString(),
            'kine' => [
                'id' => $appointment->slot->kine_id,
                'name' => $kineName,
                'email' => $appointment->slot->kine->email ?? '',
                'avatar' => $appointment->slot->kine->avatar_url ?? null,
                'phone' => $appointment->slot->kine->phone ?? null,
                'specialty' => $specialty,
                'experience_years' => $appointment->slot->kine->kineProfile->years_of_experience ?? 0,
                'about' => $appointment->slot->kine->kineProfile->bio ?? '',
            ],
            'reminders' => $appointment->reminders->map(function ($reminder) {
                return [
                    'type' => $reminder->reminder_type,
                    'hours_before' => $reminder->reminder_hours_before,
                    'sent' => !is_null($reminder->sent_at),
                    'sent_at' => $reminder->sent_at?->toISOString(),
                    'status' => $reminder->status,
                ];
            })->toArray(),
            'report' => $reportData,
            'color' => $colorName,
            'status' => $appointment->status,
            'type' => $appointment->type,
            'location' => $appointment->location,
            'isOnline' => (bool) $appointment->is_online,
            'notes' => $appointment->notes,
            'video_link' => $appointment->video_link,
            'price' => $appointment->price ? (float) $appointment->price : null,
            'created_at' => $appointment->created_at->toISOString(),
            'updated_at' => $appointment->updated_at->toISOString(),
            'cancellation_reason' => $appointment->cancellationReasons->first()?->reason ?? null,
        ];
    }

    /**
     * Get available kines (physiotherapists) for the patient
     */
    public function kines(Request $request)
    {
        $user = Auth::user();

        $kines = User::whereHas('kineProfile')
            ->whereHas('appointmentSlots.appointments', function ($query) use ($user) {
                $query->where('patient_id', $user->id);
            })
            ->with([
                'kineProfile:id,user_id,specialty,bio,years_of_experience',
                'appointmentSlots.appointments' => function ($query) use ($user) {
                    $query->where('patient_id', $user->id);
                }
            ])
            ->select(['id', 'first_name', 'last_name', 'email', 'avatar_url', 'phone'])
            ->get()
            ->map(function ($kine) {
                return [
                    'id' => $kine->id,
                    'name' => "{$kine->first_name} {$kine->last_name}",
                    'email' => $kine->email,
                    'avatar' => $kine->avatar_url,
                    'phone' => $kine->phone,
                    'specialty' => $kine->kineProfile->specialties ?? 'Kinésithérapeute général',
                    'experience_years' => $kine->kineProfile->experience_years ?? 0,
                    'about' => $kine->kineProfile->bio ?? '',
                    'appointments_count' => $kine->appointmentSlots->sum(function ($slot) {
                        return $slot->appointments->count();
                    }),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $kines,
        ]);
    }

    /**
     * Get patient's calendar statistics
     */
    public function statistics(Request $request)
    {
        $user = Auth::user();
        $period = $request->get('period', 'month');

        $startDate = match($period) {
            'week' => now()->startOfWeek(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        $endDate = match($period) {
            'week' => now()->endOfWeek(),
            'year' => now()->endOfYear(),
            default => now()->endOfMonth(),
        };

        $totalAppointments = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->count();

        $completedAppointments = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'completed')
            ->count();

        $cancelledAppointments = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'cancelled')
            ->count();

        $upcomingAppointments = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) {
                $query->where('start_time', '>', now());
            })
            ->where('status', 'scheduled')
            ->count();

        $totalKines = User::whereHas('kineProfile')
            ->whereHas('appointmentSlots.appointments', function ($query) use ($user) {
                $query->where('patient_id', $user->id);
            })
            ->count();

        $typesCount = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        return response()->json([
            'success' => true,
            'data' => [
                'total_appointments' => $totalAppointments,
                'completed_appointments' => $completedAppointments,
                'cancelled_appointments' => $cancelledAppointments,
                'upcoming_appointments' => $upcomingAppointments,
                'total_kines' => $totalKines,
                'completion_rate' => $totalAppointments > 0
                    ? round(($completedAppointments / $totalAppointments) * 100, 1)
                    : 0,
                'appointments_by_type' => $typesCount,
                'period' => $period,
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
            ]
        ]);
    }

    /**
     * Create a new appointment request (patient side)
     */
    public function storeAppointment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kine_id' => 'required|exists:users,id',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:15|max:120',
            'type' => 'required|string|in:consultation,follow_up,emergency,initial_evaluation,rehabilitation',
            'location' => 'required|string|in:cabinet,home,online,clinic',
            'notes' => 'nullable|string|max:1000',
            'reminders' => 'nullable|array',
            'reminders.*.hours_before' => 'required_with:reminders|integer|min:1|max:168',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $patient = Auth::user();
            $kine = User::find($request->kine_id);

            if (!$kine->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kinésithérapeute non disponible'
                ], 400);
            }

            $startTime = Carbon::parse($request->date . ' ' . $request->time);
            $endTime = $startTime->copy()->addMinutes($request->duration);

            $isSlotAvailable = !AppointmentSlot::where('kine_id', $kine->id)
                ->where('start_time', $startTime->toDateTimeString())
                ->where('is_available', false)
                ->exists();

            if (!$isSlotAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce créneau horaire n\'est plus disponible. Veuillez choisir un autre créneau.'
                ], 409);
            }

            $dayOfWeek = strtolower($startTime->format('l'));
            $kineProfile = $kine->kineProfile;
            $availability = $kineProfile->availability ?? [];

            if (isset($availability[$dayOfWeek]) && !$availability[$dayOfWeek]['enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le kinésithérapeute ne travaille pas ce jour'
                ], 400);
            }

            $slot = AppointmentSlot::create([
                'kine_id' => $kine->id,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_available' => false,
            ]);

            $appointment = Appointment::create([
                'patient_id' => $patient->id,
                'slot_id' => $slot->id,
                'type' => $request->type,
                'status' => 'pending',
                'notes' => $request->notes,
                'location' => $request->location,
                'is_online' => $request->location === 'online',
                'price' => null,
            ]);

            // Add reminders if provided
            if ($request->reminders) {
                foreach ($request->reminders as $reminder) {
                    $appointment->reminders()->create([
                        'reminder_type' => 'email',
                        'reminder_hours_before' => $reminder['hours_before'],
                        'status' => 'scheduled',
                    ]);
                }
            }

            // Send notification to kine about new appointment request
            $this->createNotification(
                $kine->id,
                'appointment.requested',
                'Nouvelle demande de rendez-vous',
                "Le patient {$patient->first_name} {$patient->last_name} a demandé un rendez-vous pour le " . $startTime->format('d/m/Y H:i'),
                NotificationPriority::MEDIUM,
                [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'start_time' => $startTime->toISOString(),
                    'type' => $appointment->type,
                ],
                "/kine/calendar?appointment={$appointment->id}"
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Demande de rendez-vous envoyée avec succès. Le kinésithérapeute confirmera votre rendez-vous et fixera le prix.',
                'data' => new AppointmentResource($appointment->load(['slot.kine']))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Patient appointment creation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la demande de rendez-vous',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update appointment (patient can update pending AND confirmed/scheduled appointments)
     */
    public function updateAppointment(Request $request, $id)
    {
        $patient = Auth::user();

        $appointment = Appointment::where('patient_id', $patient->id)
            ->whereIn('status', ['pending', 'confirmed', 'scheduled'])
            ->with(['slot', 'slot.kine'])
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'duration' => 'nullable|integer|min:15|max:120',
            'type' => 'nullable|string|in:consultation,follow_up,emergency,initial_evaluation,rehabilitation',
            'location' => 'nullable|string|in:cabinet,home,online,clinic',
            'notes' => 'nullable|string|max:1000',
            'reminders' => 'nullable|array',
            'reminders.*.hours_before' => 'required_with:reminders|integer|min:1|max:168',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $kine = $appointment->slot->kine;
            $slot = $appointment->slot;
            $oldStartTime = $slot->start_time->copy();

            // If date or time is being changed, check availability
            if ($request->has('date') || $request->has('time')) {
                $newDate = $request->date ? Carbon::parse($request->date) : $slot->start_time;
                $newTime = $request->time ? Carbon::parse($newDate->toDateString() . ' ' . $request->time) : $slot->start_time;

                // Combine date and time
                $newStartTime = Carbon::create(
                    $newDate->year, $newDate->month, $newDate->day,
                    $newTime->hour, $newTime->minute, 0
                );

                $newDuration = $request->duration ?? $slot->end_time->diffInMinutes($slot->start_time);
                $newEndTime = $newStartTime->copy()->addMinutes($newDuration);

                // Check if the date is in the past
                if ($newStartTime->isPast()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez pas reprogrammer un rendez-vous dans le passé'
                    ], 400);
                }

                // Check if the new slot is available (excluding current appointment)
                $isSlotAvailable = !AppointmentSlot::where('kine_id', $kine->id)
                    ->where('start_time', $newStartTime->toDateTimeString())
                    ->where('is_available', false)
                    ->where('id', '!=', $slot->id)
                    ->exists();

                if (!$isSlotAvailable) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Le nouveau créneau horaire n\'est pas disponible'
                    ], 409);
                }

                // Update slot
                $slot->update([
                    'start_time' => $newStartTime,
                    'end_time' => $newEndTime,
                ]);
            }

            // Update appointment details
            $appointment->update([
                'type' => $request->type ?? $appointment->type,
                'notes' => $request->notes ?? $appointment->notes,
                'location' => $request->location ?? $appointment->location,
                'is_online' => $request->location ? ($request->location === 'online') : $appointment->is_online,
            ]);

            // Update duration if provided without changing time
            if ($request->has('duration') && !$request->has('time') && !$request->has('date')) {
                $newEndTime = $slot->start_time->copy()->addMinutes($request->duration);
                $slot->update(['end_time' => $newEndTime]);
            }

            // Update reminders
            if ($request->has('reminders')) {
                $appointment->reminders()->delete();

                foreach ($request->reminders as $reminder) {
                    $appointment->reminders()->create([
                        'reminder_type' => 'email',
                        'reminder_hours_before' => $reminder['hours_before'],
                        'status' => 'scheduled',
                    ]);
                }
            }

            // Send notification to kine about appointment update
            $this->createNotification(
                $kine->id,
                'appointment.updated_by_patient',
                'Rendez-vous modifié par le patient',
                "Le patient {$patient->first_name} {$patient->last_name} a modifié son rendez-vous du " . $oldStartTime->format('d/m/Y H:i') . " au " . $slot->start_time->format('d/m/Y H:i'),
                NotificationPriority::MEDIUM,
                [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'old_start_time' => $oldStartTime->toISOString(),
                    'new_start_time' => $slot->start_time->toISOString(),
                ],
                "/kine/calendar?appointment={$appointment->id}"
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous reprogrammé avec succès',
                'data' => new AppointmentResource($appointment->fresh(['slot.kine', 'reminders']))
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Patient appointment update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la reprogrammation du rendez-vous'
            ], 500);
        }
    }

    /**
     * Cancel appointment
     */
    public function cancelAppointment(Request $request, $id)
    {
        $patient = Auth::user();

        $appointment = Appointment::where('patient_id', $patient->id)
            ->whereIn('status', ['pending', 'confirmed', 'scheduled'])
            ->with(['slot', 'slot.kine'])
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'additional_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($appointment->status === 'confirmed' || $appointment->status === 'scheduled') {
            $hoursUntilAppointment = now()->diffInHours($appointment->slot->start_time, false);
            if ($hoursUntilAppointment < 24 && $hoursUntilAppointment > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les annulations doivent être faites au moins 24h à l\'avance'
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;
            $oldStartTime = $appointment->slot->start_time->copy();

            $appointment->status = 'cancelled';
            $appointment->cancelled_by = 'patient';
            $appointment->save();

            if ($request->has('additional_notes') && !empty($request->additional_notes)) {
                AppointmentCancellationReason::create([
                    'appointment_id' => $appointment->id,
                    'cancellation_reason_id' => null,
                    'additional_notes' => $request->additional_notes,
                ]);
            }

            $appointment->slot()->update(['is_available' => true]);

            $this->createNotification(
                $appointment->slot->kine_id,
                'appointment.cancelled_by_patient',
                'Rendez-vous annulé par le patient',
                "Le patient {$patient->first_name} {$patient->last_name} a annulé son rendez-vous du " . $oldStartTime->format('d/m/Y H:i'),
                NotificationPriority::HIGH,
                [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'start_time' => $oldStartTime->toISOString(),
                    'additional_notes' => $request->additional_notes,
                ],
                "/kine/calendar?appointment={$appointment->id}"
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous annulé avec succès',
                'data' => new AppointmentResource($appointment->fresh(['slot.kine', 'cancellationReasons']))
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Patient appointment cancellation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation du rendez-vous'
            ], 500);
        }
    }

    /**
     * Get cancellation reasons for patients
     */
    public function getCancellationReasons()
    {
        $reasons = CancellationReason::where('is_active', true)
            ->orderBy('order_index', 'asc')
            ->get(['id', 'reason', 'type']);

        return response()->json([
            'success' => true,
            'data' => $reasons
        ]);
    }

    /**
     * Get kine settings for patient view
     */
    public function getKineSettings($kineId)
    {
        $user = Auth::user();

        $kine = User::whereHas('kineProfile')
            ->with(['kineProfile', 'appointmentTypeSettings'])
            ->findOrFail($kineId);

        $availabilitySettings = AvailabilitySetting::where('kine_id', $kineId)->first();
        $appointmentTypes = AppointmentTypeSetting::where('kine_id', $kineId)->get();

        // Create default settings if none exist
        if (!$availabilitySettings) {
            $availabilitySettings = AvailabilitySetting::create([
                'kine_id' => $kineId,
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
            ]);
        }

        // Create default appointment types if none exist
        $defaultTypes = ['consultation', 'follow_up', 'emergency', 'initial_evaluation', 'rehabilitation'];
        foreach ($defaultTypes as $type) {
            AppointmentTypeSetting::firstOrCreate(
                [
                    'kine_id' => $kineId,
                    'type' => $type,
                ],
                [
                    'default_duration' => 30,
                    'default_price' => 50,
                    'is_active' => true,
                ]
            );
        }

        $appointmentTypes = AppointmentTypeSetting::where('kine_id', $kineId)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $kine->id,
                'name' => $kine->first_name . ' ' . $kine->last_name,
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
                'appointment_types' => $appointmentTypes->map(function ($type) {
                    return [
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
     * Get kine availability info for patient view
     */
    public function getKineAvailability($kineId)
    {
        $user = Auth::user();

        $availabilitySettings = AvailabilitySetting::where('kine_id', $kineId)->first();

        if (!$availabilitySettings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'working_days' => [
                        'monday' => true,
                        'tuesday' => true,
                        'wednesday' => true,
                        'thursday' => true,
                        'friday' => true,
                        'saturday' => false,
                        'sunday' => false,
                    ],
                    'working_hours' => [
                        'start' => '08:00',
                        'end' => '18:00',
                        'lunch_start' => '12:00',
                        'lunch_end' => '14:00',
                        'has_lunch_break' => true,
                    ],
                ],
            ]);
        }

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
            ],
        ]);
    }

/**
 * Get available time slots for a specific kine and date
 */
public function getAvailableSlots(Request $request)
{
    $validator = Validator::make($request->all(), [
        'kine_id' => 'required|exists:users,id',
        'date' => 'required|date',
        'duration' => 'sometimes|integer|min:15|max:120',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $patient = Auth::user();
    $date = Carbon::parse($request->date);
    $kineId = $request->kine_id;
    $duration = $request->has('duration') ? (int) $request->duration : 30;

    $kine = User::with('kineProfile')->find($kineId);

    if (!$kine) {
        return response()->json([
            'success' => false,
            'message' => 'Kinésithérapeute non trouvé'
        ], 404);
    }

    if (!$kine->kineProfile) {
        return response()->json([
            'success' => false,
            'message' => 'Profil kinésithérapeute non trouvé'
        ], 404);
    }

    $workingHours = $kine->kineProfile->working_hours ?? [
        'start' => '08:00',
        'end' => '18:00',
        'lunch_break_start' => '12:00',
        'lunch_break_end' => '14:00',
    ];

    $dayOfWeek = strtolower($date->format('l'));
    $availability = $kine->kineProfile->availability ?? [];

    // Check if kine works on this day
    if (isset($availability[$dayOfWeek]) && !$availability[$dayOfWeek]['enabled']) {
        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'Le kinésithérapeute ne travaille pas ce jour'
        ]);
    }

    // Get booked slots for the day
    $bookedSlots = AppointmentSlot::where('kine_id', $kineId)
        ->whereDate('start_time', $date->toDateString())
        ->where('is_available', false)
        ->pluck('start_time')
        ->map(function ($slot) {
            return Carbon::parse($slot);
        })
        ->toArray();

    $availableSlots = [];
    $slotDuration = $duration;

    // Use day-specific working hours or default
    $dayHours = $availability[$dayOfWeek]['hours'] ?? $workingHours;

    // Parse time strings to Carbon instances
    $startTime = Carbon::createFromTimeString($dayHours['start'] ?? '08:00');
    $endTime = Carbon::createFromTimeString($dayHours['end'] ?? '18:00');

    // Handle lunch break
    $lunchStart = isset($dayHours['lunch_break_start'])
        ? Carbon::createFromTimeString($dayHours['lunch_break_start'])
        : Carbon::createFromTimeString('12:00');
    $lunchEnd = isset($dayHours['lunch_break_end'])
        ? Carbon::createFromTimeString($dayHours['lunch_break_end'])
        : Carbon::createFromTimeString('14:00');

    // Start from the beginning of the day
    $currentTime = $startTime->copy();

    // Add a safety counter to prevent infinite loops
    $maxIterations = 100;
    $iteration = 0;

    while ($currentTime->copy()->addMinutes($slotDuration)->lte($endTime) && $iteration < $maxIterations) {
        $iteration++;

        $slotEndTime = $currentTime->copy()->addMinutes($slotDuration);
        $slotTime = $currentTime->copy();

        // Skip if slot is in the past (for today)
        if ($date->isToday() && $slotTime->lt(now())) {
            $currentTime->addMinutes($slotDuration);
            continue;
        }

        // Check if slot overlaps with lunch break
        $overlapsLunch = (
            ($slotTime->gte($lunchStart) && $slotTime->lt($lunchEnd)) ||
            ($slotEndTime->gt($lunchStart) && $slotEndTime->lte($lunchEnd)) ||
            ($slotTime->lt($lunchStart) && $slotEndTime->gt($lunchStart))
        );

        if ($overlapsLunch) {
            // Jump to end of lunch break
            $currentTime = $lunchEnd->copy();
            continue;
        }

        // Check if slot is booked
        $isBooked = false;
        foreach ($bookedSlots as $bookedTime) {
            $bookedEndTime = $bookedTime->copy()->addMinutes(30); // Assuming booked slots are 30 min

            // Check for overlap
            if ($slotTime->lt($bookedEndTime) && $slotEndTime->gt($bookedTime)) {
                $isBooked = true;
                break;
            }
        }

        // Add slot if not booked
        if (!$isBooked) {
            $availableSlots[] = [
                'start_time' => $slotTime->toISOString(),
                'end_time' => $slotEndTime->toISOString(),
                'duration' => $slotDuration,
                'formatted_time' => $slotTime->format('H:i'),
                'time_slot' => $slotTime->format('H:i') . ' - ' . $slotEndTime->format('H:i'),
            ];
        }

        // Move to next slot
        $currentTime->addMinutes($slotDuration);
    }

    \Log::info('Available slots generated', [
        'count' => count($availableSlots),
        'duration' => $slotDuration,
        'date' => $date->toDateString()
    ]);

    return response()->json([
        'success' => true,
        'data' => $availableSlots
    ]);
}
    /**
     * Request appointment cancellation (kept for backward compatibility)
     */
    public function requestCancellation(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'cancellation_reason_id' => 'required|exists:cancellation_reasons,id',
            'additional_notes' => 'nullable|string|max:500',
        ]);

        $appointment = Appointment::where('patient_id', $user->id)
            ->with(['slot'])
            ->findOrFail($id);

        if ($appointment->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Le rendez-vous est déjà annulé',
            ], 400);
        }

        if ($appointment->status !== 'scheduled') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous programmés peuvent être annulés',
            ], 400);
        }

        // Check if appointment is in less than 24 hours
        $hoursUntilAppointment = now()->diffInHours($appointment->slot->start_time, false);
        if ($hoursUntilAppointment < 24 && $hoursUntilAppointment > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Les annulations doivent être faites au moins 24h à l\'avance',
            ], 400);
        }

        // Update appointment status to cancellation requested
        $appointment->status = 'cancellation_requested';
        $appointment->save();

        // Store cancellation reason (awaiting kine confirmation)
        \App\Models\AppointmentCancellationReason::create([
            'appointment_id' => $appointment->id,
            'cancellation_reason_id' => $request->cancellation_reason_id,
            'additional_notes' => $request->additional_notes,
            'requested_by_patient' => true,
        ]);

        // Send notification to kine about cancellation request
        $reason = CancellationReason::find($request->cancellation_reason_id);

        $this->createNotification(
            $appointment->slot->kine_id,
            'appointment.cancellation_requested',
            'Demande d\'annulation de rendez-vous',
            "Le patient demande l'annulation de son rendez-vous du " . $appointment->slot->start_time->format('d/m/Y H:i'),
            NotificationPriority::HIGH,
            [
                'appointment_id' => $appointment->id,
                'patient_id' => $user->id,
                'patient_name' => $user->first_name . ' ' . $user->last_name,
                'start_time' => $appointment->slot->start_time->toISOString(),
                'reason' => $reason ? $reason->reason : 'Non spécifié',
                'additional_notes' => $request->additional_notes,
            ],
            "/kine/calendar?appointment={$appointment->id}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'annulation envoyée au kinésithérapeute',
            'data' => new AppointmentResource($appointment->load(['slot.kine', 'cancellationReasons'])),
        ]);
    }

    /**
     * Get patient's upcoming appointments
     */
    public function upcoming(Request $request)
    {
        $user = Auth::user();
        $limit = $request->get('limit', 5);

        $appointments = Appointment::where('patient_id', $user->id)
            ->whereHas('slot', function ($query) {
                $query->where('start_time', '>', now());
            })
            ->where('status', 'scheduled')
            ->with([
                'slot.kine:id,first_name,last_name,avatar_url',
                'slot:id,start_time,end_time'
            ])
            ->select(['id', 'patient_id', 'slot_id', 'type', 'status', 'location', 'is_online'])
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($appointment) {
                return [
                    'id' => $appointment->id,
                    'type' => $appointment->type,
                    'status' => $appointment->status,
                    'location' => $appointment->location,
                    'is_online' => $appointment->is_online,
                    'date' => $appointment->slot->start_time->toDateString(),
                    'time' => $appointment->slot->start_time->format('H:i'),
                    'duration' => $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time),
                    'kine' => $appointment->slot->kine ? [
                        'id' => $appointment->slot->kine->id,
                        'name' => "{$appointment->slot->kine->first_name} {$appointment->slot->kine->last_name}",
                        'avatar' => $appointment->slot->kine->avatar_url,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Helper method to create notifications
     */
    private function createNotification($userId, $type, $title, $message, $priority, $metadata = null, $actionUrl = null)
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'metadata' => $metadata,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);

        broadcast(new NewNotification($notification));

        return $notification;
    }
}
