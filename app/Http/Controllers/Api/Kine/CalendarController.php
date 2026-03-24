<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\CancellationReason;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\AppointmentReminder;
use App\Events\NewNotification;
use App\Models\AppointmentSlot;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CalendarController extends Controller
{

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of appointments for the current kine.
     */
    public function appointments(Request $request)
    {
        $user = Auth::user();

        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', '');
        $status = $request->get('status', 'all');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with([
                'patient:id,first_name,last_name,email',
                'slot:id,start_time,end_time',
                'reminders:id,appointment_id,reminder_type'
            ])
            ->select(['id', 'patient_id', 'slot_id', 'type', 'status', 'notes',
                    'created_at', 'location', 'is_online'])
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('type', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($q) use ($search) {
                      $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
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
     * Get calendar events for specific date range
     */
    public function events(Request $request)
    {
        $user = Auth::user();

        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
        $patientId = $request->get('patient_id');

        $query = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->whereHas('slot', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->with([
                'patient:id,first_name,last_name,email,avatar_url,phone',
                'slot:id,start_time,end_time,kine_id',
                'reminders:id,appointment_id,reminder_type,reminder_hours_before,sent_at,status',
                'report.documents'
            ])
            ->select(['id', 'patient_id', 'slot_id', 'type', 'notes', 'status', 'location',
                    'is_online', 'video_link', 'price', 'created_at', 'updated_at'])
            ->orderBy('created_at', 'desc');

        if ($patientId) {
            $query->where('patient_id', $patientId);
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

        $fullName = $appointment->patient
            ? "{$appointment->patient->first_name} {$appointment->patient->last_name}"
            : 'Patient';

        return [
            'id' => $appointment->id,
            'title' => $appointment->patient ? "Rendez-vous avec {$fullName}" : "Rendez-vous",
            'description' => $appointment->notes,
            'startDate' => $appointment->slot->start_time->toISOString(),
            'endDate' => $appointment->slot->end_time->toISOString(),
            'user' => [
                'id' => $appointment->patient_id,
                'name' => $fullName,
                'email' => $appointment->patient->email ?? '',
                'picturePath' => $appointment->patient->avatar_url ?? null,
                'phone' => $appointment->patient->phone ?? null,
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
            'color' => $colorName,
            'status' => $appointment->status,
            'type' => $appointment->type,
            'location' => $appointment->location,
            'isOnline' => (bool) $appointment->is_online,
            'notes' => $appointment->notes,
            'video_link' => $appointment->video_link,
            'meeting_code' => $appointment->meeting_code,
            'price' => $appointment->price ? (float) $appointment->price : null,
            'report' => $appointment->report ? [
                'id' => $appointment->report->id,
                'notes' => $appointment->report->notes,
                'documents' => $appointment->report->documents->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'filename' => $doc->filename,
                        'file_path' => $doc->file_path,
                        'file_size' => $doc->file_size,
                        'mime_type' => $doc->mime_type,
                        'created_at' => $doc->created_at?->toISOString(),
                    ];
                })->toArray(),
                'created_at' => $appointment->report->created_at?->toISOString(),
            ] : null,
            'created_at' => $appointment->created_at->toISOString(),
            'updated_at' => $appointment->updated_at->toISOString(),
        ];
    }

    /**
     * Get available time slots for scheduling
     */
    public function availableSlots(Request $request)
    {
        $user = Auth::user();
        $date = $request->get('date', now()->toDateString());
        $duration = (int) $request->get('duration', 30);
        $patientId = $request->get('patient_id');

        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        $kineProfile = $user->kineProfile;
        $workingDays = $kineProfile->working_days ?? [];
        $workingHours = $kineProfile->working_hours ?? [];

        $dayOfWeek = strtolower($startOfDay->englishDayOfWeek);

        if (!($workingDays[$dayOfWeek] ?? false)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No working hours for this day'
            ]);
        }

        $bookedSlots = Appointment::whereHas('slot', function ($query) use ($user, $startOfDay, $endOfDay) {
                $query->where('kine_id', $user->id)
                    ->whereBetween('start_time', [$startOfDay, $endOfDay]);
            })
            ->with('slot')
            ->get()
            ->map(function ($appointment) {
                return [
                    'start' => $appointment->slot->start_time,
                    'end' => $appointment->slot->end_time,
                ];
            })
            ->toArray();

        $availableSlots = [];

        $workStart = Carbon::parse($date . ' ' . ($workingHours['start'] ?? '08:00'));
        $workEnd = Carbon::parse($date . ' ' . ($workingHours['end'] ?? '18:00'));
        $lunchStart = Carbon::parse($date . ' ' . ($workingHours['lunch_start'] ?? '12:00'));
        $lunchEnd = Carbon::parse($date . ' ' . ($workingHours['lunch_end'] ?? '14:00'));

        $currentSlot = $workStart->copy();

        while ($currentSlot->copy()->addMinutes($duration)->lte($workEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($duration);

            if ($this->isDuringLunchBreak($currentSlot, $slotEnd, $lunchStart, $lunchEnd)) {
                $currentSlot = $lunchEnd->copy();
                continue;
            }

            $isAvailable = true;
            foreach ($bookedSlots as $booked) {
                $bookedStart = Carbon::parse($booked['start']);
                $bookedEnd = Carbon::parse($booked['end']);

                if ($currentSlot->lt($bookedEnd) && $slotEnd->gt($bookedStart)) {
                    $isAvailable = false;
                    break;
                }
            }

            if ($isAvailable) {
                $availableSlots[] = [
                    'start_time' => $currentSlot->toISOString(),
                    'end_time' => $slotEnd->toISOString(),
                    'duration' => $duration,
                    'is_available' => true,
                ];
            }

            $currentSlot = $currentSlot->addMinutes($duration);
        }

        return response()->json([
            'success' => true,
            'data' => $availableSlots,
        ]);
    }

    /**
     * Check if a time slot overlaps with lunch break
     */
    private function isDuringLunchBreak($start, $end, $lunchStart, $lunchEnd)
    {
        return $start->lt($lunchEnd) && $end->gt($lunchStart);
    }

    /**
     * Create a new appointment
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $existingAppointment = Appointment::whereHas('slot', function ($query) use ($request) {
            $query->where('start_time', $request->start_time)
                ->where('end_time', $request->end_time);
        })->exists();

        if ($existingAppointment) {
            return response()->json([
                'success' => false,
                'message' => 'Ce créneau est déjà réservé. Veuillez choisir un autre horaire.',
            ], 409);
        }

        DB::beginTransaction();

        try {
            $slot = AppointmentSlot::create([
                'kine_id' => $user->id,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'is_available' => false,
            ]);

            $appointment = Appointment::create([
                'patient_id' => $request->patient_id,
                'slot_id' => $slot->id,
                'type' => $request->type ?? 'consultation',
                'status' => 'scheduled',
                'notes' => $request->notes,
                'location' => $request->location,
                'is_online' => $request->is_online ?? false,
                'video_link' => $request->video_link,
                'meeting_code' => $request->meeting_code,
                'price' => $request->price,
            ]);


            if ($request->reminders) {
                foreach ($request->reminders as $reminder) {
                    $appointment->reminders()->create([
                        'reminder_type' => $reminder['type'],
                        'reminder_hours_before' => $reminder['hours_before'],
                        'status' => 'scheduled',
                    ]);
                }
            }

            if ($appointment->patient) {
                $this->createNotification(
                    $appointment->patient_id,
                    'appointment.created',
                    'Nouveau rendez-vous',
                    "Un rendez-vous a été créé pour le " . $slot->start_time->format('d/m/Y H:i'),
                    NotificationPriority::MEDIUM,
                    [
                        'appointment_id' => $appointment->id,
                        'start_time' => $slot->start_time->toISOString(),
                        'type' => $appointment->type,
                    ],
                    "/patient/appointments/{$appointment->id}"
                );
            }

            // Send confirmation notification
            // $this->notificationService->sendConfirmationNotification($appointment);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous créé avec succès',
                'data' => new AppointmentResource($appointment),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du rendez-vous',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an appointment
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'patient'])
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            if ($request->has('start_time') || $request->has('end_time')) {
                $appointment->slot->update([
                    'start_time' => $request->start_time ?? $appointment->slot->start_time,
                    'end_time' => $request->end_time ?? $appointment->slot->end_time,
                ]);
            }

            $appointment->update([
                'type' => $request->type ?? $appointment->type,
                'notes' => $request->notes ?? $appointment->notes,
                'location' => $request->location ?? $appointment->location,
                'is_online' => $request->is_online ?? $appointment->is_online,
                'video_link' => $request->video_link ?? $appointment->video_link,
                'meeting_code' => $request->meeting_code ?? $appointment->meeting_code,
                'price' => $request->price ?? $appointment->price,
            ]);

            // Send confirmation notification
            $this->notificationService->sendConfirmationNotification($appointment);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous mis à jour avec succès',
                'data' => new AppointmentResource($appointment->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du rendez-vous',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active cancellation reasons
     */
    public function getCancellationReasons()
    {
        $reasons = CancellationReason::active()->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $reasons,
        ]);
    }

    /**
     * Get appointment cancellation details
     */
    public function getCancellationDetails($id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['cancellationReasons'])
            ->findOrFail($id);

        if ($appointment->status !== 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Le rendez-vous n\'est pas annulé',
            ], 400);
        }

        $cancellation = $appointment->cancellationReasons->first();

        return response()->json([
            'success' => true,
            'data' => [
                'additional_notes' => $cancellation ? $cancellation->additional_notes : null,
                'cancelled_by' => $appointment->cancelled_by ?? 'kine',
                'cancelled_at' => $appointment->updated_at,
            ],
        ]);
    }

    /**
     * Cancel an appointment
     */
    public function cancelWithReason(Request $request, $id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'reminders', 'patient'])
            ->findOrFail($id);

        if ($appointment->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Le rendez-vous est déjà annulé',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;
            $appointment->status = "cancelled";
            $appointment->cancelled_by = "kine";
            $appointment->save();

            $appointment->slot->update([
                'is_available' => true
            ]);

            if ($request->has('additional_notes') && !empty($request->additional_notes)) {
                AppointmentCancellationReason::create([
                    'appointment_id' => $appointment->id,
                    'cancellation_reason_id' => null,
                    'additional_notes' => $request->additional_notes,
                ]);
            }

            if ($appointment->patient) {
                $this->createNotification(
                    $appointment->patient_id,
                    'appointment.cancelled',
                    'Rendez-vous annulé',
                    "Votre rendez-vous du " . $appointment->slot->start_time->format('d/m/Y H:i') . " a été annulé.",
                    NotificationPriority::HIGH,
                    [
                        'appointment_id' => $appointment->id,
                        'additional_notes' => $request->additional_notes,
                        'cancelled_by' => 'kine',
                    ],
                    "/patient/appointments/{$appointment->id}"
                );

            }

            // Send cancellation notification
            // $this->notificationService->sendCancellationNotification(
            //     $appointment,
            //     'kine',
            //     $request->additional_notes ?? null
            // );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous annulé avec succès',
                'data' => new AppointmentResource($appointment->load(['patient', 'slot', 'reminders', 'cancellationReasons'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation du rendez-vous',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete an appointment with report
     */
    public function complete($id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'reminders', 'patient'])
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;
            $appointment->status = "completed";
            $appointment->save();

            // Send notification to patient
            if ($appointment->patient) {
                $this->createNotification(
                    $appointment->patient_id,
                    'appointment.completed',
                    'Séance terminée',
                    "Votre séance du " . $appointment->slot->start_time->format('d/m/Y H:i') . " a été marquée comme terminée.",
                    NotificationPriority::MEDIUM,
                    [
                        'appointment_id' => $appointment->id,
                        'start_time' => $appointment->slot->start_time->toISOString(),
                    ],
                    "/patient/appointments/{$appointment->id}/report"
                );

            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous marqué comme terminé',
                'data' => new AppointmentResource($appointment->load(['patient', 'slot', 'reminders'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du rendez-vous',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a pending appointment
     */
    public function approve($id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'reminders', 'patient'])
            ->findOrFail($id);

        if ($appointment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous en attente peuvent être approuvés',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;
            $appointment->status = "scheduled";
            $appointment->save();

            // Send notification to patient
            if ($appointment->patient) {
                $this->createNotification(
                    $appointment->patient_id,
                    'appointment.approved',
                    'Rendez-vous confirmé',
                    "Votre rendez-vous du " . $appointment->slot->start_time->format('d/m/Y H:i') . " a été confirmé.",
                    NotificationPriority::MEDIUM,
                    [
                        'appointment_id' => $appointment->id,
                        'start_time' => $appointment->slot->start_time->toISOString(),
                    ],
                    "/patient/appointments/{$appointment->id}"
                );

            }

            // Send confirmation notification
            // $this->notificationService->sendConfirmationNotification($appointment);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous approuvé avec succès',
                'data' => new AppointmentResource($appointment->load(['patient', 'slot', 'reminders'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'approbation du rendez-vous',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get appointment statistics
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

        $totalAppointments = Appointment::whereHas('slot', function ($query) use ($user, $startDate, $endDate) {
                $query->where('kine_id', $user->id)
                      ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->count();

        $completedAppointments = Appointment::whereHas('slot', function ($query) use ($user, $startDate, $endDate) {
                $query->where('kine_id', $user->id)
                      ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'completed')
            ->count();

        $cancelledAppointments = Appointment::whereHas('slot', function ($query) use ($user, $startDate, $endDate) {
                $query->where('kine_id', $user->id)
                      ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'cancelled')
            ->count();

        $upcomingAppointments = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id)
                      ->where('start_time', '>', now());
            })
            ->where('status', 'scheduled')
            ->count();

        $averageDuration = Appointment::whereHas('slot', function ($query) use ($user, $startDate, $endDate) {
                $query->where('kine_id', $user->id)
                      ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'completed')
            ->with('slot')
            ->get()
            ->avg(function ($appointment) {
                return $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time);
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total_appointments' => $totalAppointments,
                'completed_appointments' => $completedAppointments,
                'cancelled_appointments' => $cancelledAppointments,
                'upcoming_appointments' => $upcomingAppointments,
                'completion_rate' => $totalAppointments > 0 ?
                    round(($completedAppointments / $totalAppointments) * 100, 1) : 0,
                'average_duration_minutes' => round($averageDuration ?? 0),
                'period' => $period,
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
            ]
        ]);
    }

    /**
     * Send appointment reminder
     */
    public function sendReminder($id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['patient', 'slot'])
            ->findOrFail($id);

        if ($appointment->patient) {
            // Send notification to patient
            $this->createNotification(
                $appointment->patient_id,
                'appointment.reminder',
                'Rappel de rendez-vous',
                "Vous avez un rendez-vous " . $appointment->slot->start_time->diffForHumans(),
                NotificationPriority::MEDIUM,
                [
                    'appointment_id' => $appointment->id,
                    'start_time' => $appointment->slot->start_time->toISOString(),
                    'type' => $appointment->type,
                ],
                "/patient/appointments/{$appointment->id}"
            );

        }

        return response()->json([
            'success' => true,
            'message' => 'Rappel envoyé avec succès'
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

        // Broadcast real-time notification
        broadcast(new NewNotification($notification));

        return $notification;
    }
}
