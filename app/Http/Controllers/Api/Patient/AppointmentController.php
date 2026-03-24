<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Models\CancellationReason;
use App\Models\AppointmentCancellationReason;
use App\Notifications\AppointmentCreated;
use App\Notifications\AppointmentUpdated;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentConfirmed;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    /**
     * Get all appointments with filters
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Appointment::query();

        // Check user role and apply appropriate filters
        if ($user->hasRole('kine')) {
            $query->whereHas('slot', function ($q) use ($user) {
                $q->where('kine_id', $user->id);
            });
        } elseif ($user->hasRole('patient')) {
            $query->where('patient_id', $user->id);
        } else {
            // Admin can see all appointments
        }

        // Apply filters
        $this->applyFilters($query, $request);

        // Eager load relationships
        $query->with([
            'patient:id,first_name,last_name,email,avatar_url,phone',
            'slot.kine:id,first_name,last_name,email,avatar_url,phone,kine_profile_id',
            'slot.kine.kineProfile:user_id,specialty,bio,years_of_experience',
            'cancellationReasons.reason',
            'reminders'
        ]);

        // Order by date
        $query->orderBy('created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $appointments = $query->paginate($perPage);

        return AppointmentResource::collection($appointments);
    }

    /**
     * Get available slots for a specific kine and date
     */
    public function availableSlots(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kine_id' => 'required|exists:users,id',
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $date = Carbon::parse($request->date);
        $kineId = $request->kine_id;

        // Get kine's working hours
        $kine = User::with('kineProfile')->find($kineId);

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

        // Get booked slots for the day
        $bookedSlots = AppointmentSlot::where('kine_id', $kineId)
            ->whereDate('start_time', $date->toDateString())
            ->where('is_available', false)
            ->pluck('start_time')
            ->toArray();

        // Generate available slots
        $availableSlots = [];
        $slotDuration = 30; // minutes
        $currentTime = Carbon::createFromTimeString($workingHours['start']);
        $endTime = Carbon::createFromTimeString($workingHours['end']);
        $lunchStart = Carbon::createFromTimeString($workingHours['lunch_break_start'] ?? '12:00');
        $lunchEnd = Carbon::createFromTimeString($workingHours['lunch_break_end'] ?? '14:00');

        while ($currentTime->addMinutes($slotDuration)->lte($endTime)) {
            $slotTime = $currentTime->copy()->subMinutes($slotDuration);

            // Skip lunch break
            if ($slotTime->between($lunchStart, $lunchEnd->subMinutes($slotDuration))) {
                $currentTime->addMinutes($slotDuration);
                continue;
            }

            // Check if slot is not booked
            if (!in_array($slotTime->toDateTimeString(), $bookedSlots)) {
                $availableSlots[] = [
                    'start_time' => $slotTime->toISOString(),
                    'end_time' => $currentTime->toISOString(),
                    'duration' => $slotDuration
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $availableSlots
        ]);
    }

    /**
     * Store a new appointment request
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kine_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:15|max:120',
            'type' => 'required|string|in:consultation,follow_up,emergency,initial_evaluation,rehabilitation',
            'location' => 'required|string|in:cabinet,home,online,clinic',
            'notes' => 'nullable|string|max:1000',
            'reminders' => 'nullable|array',
            'reminders.*.hours_before' => 'required|integer|min:1|max:168',
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

            // Create start and end times
            $startTime = Carbon::parse($request->date . ' ' . $request->time);
            $endTime = $startTime->copy()->addMinutes($request->duration);

            // Check if slot is available
            $existingSlot = AppointmentSlot::where('kine_id', $kine->id)
                ->where('start_time', $startTime->toDateTimeString())
                ->where('is_available', false)
                ->first();

            if ($existingSlot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce créneau n\'est plus disponible'
                ], 409);
            }

            // Create appointment slot
            $slot = AppointmentSlot::create([
                'kine_id' => $kine->id,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_available' => false,
            ]);

            // Create appointment with pending status
            $appointment = Appointment::create([
                'patient_id' => $patient->id,
                'slot_id' => $slot->id,
                'type' => $request->type,
                'status' => 'pending', // Waiting for kine approval
                'notes' => $request->notes,
                'location' => $request->location,
                'is_online' => $request->location === 'online',
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

            // Send notification to kine
            $kine->notify(new AppointmentCreated($appointment));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Demande de rendez-vous envoyée avec succès',
                'data' => new AppointmentResource($appointment->load(['patient', 'slot.kine']))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Appointment creation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du rendez-vous',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Show appointment details
     */
    public function show($id)
    {
        $user = Auth::user();

        $appointment = Appointment::with([
            'patient:id,first_name,last_name,email,avatar_url,phone',
            'slot.kine:id,first_name,last_name,email,avatar_url,phone,kine_profile_id',
            'slot.kine.kineProfile:user_id,specialty,bio,years_of_experience',
            'cancellationReasons.reason',
            'reminders'
        ])->findOrFail($id);

        // Check authorization
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment);
    }

    /**
     * Update appointment
     */
    public function update(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('update', $appointment);

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|string|in:consultation,follow_up,emergency,initial_evaluation,rehabilitation',
            'notes' => 'nullable|string|max:1000',
            'location' => 'sometimes|string|in:cabinet,home,online,clinic',
            'price' => 'nullable|numeric|min:0',
            'video_link' => 'nullable|url',
            'status' => 'sometimes|string|in:pending,confirmed,cancelled,completed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;

            // Update appointment
            $appointment->update([
                'type' => $request->get('type', $appointment->type),
                'notes' => $request->get('notes', $appointment->notes),
                'location' => $request->get('location', $appointment->location),
                'is_online' => $request->get('location', $appointment->location) === 'online',
                'video_link' => $request->get('video_link', $appointment->video_link),
                'price' => $request->has('price') ? $request->price : $appointment->price,
                'status' => $request->get('status', $appointment->status),
            ]);

            // Handle status changes
            if ($request->has('status') && $request->status !== $oldStatus) {
                $this->handleStatusChange($appointment, $oldStatus, $request->status);
            }

            // Update slot if time changed
            if ($request->has('start_time')) {
                $slot = $appointment->slot;
                $slot->update([
                    'start_time' => Carbon::parse($request->start_time),
                    'end_time' => Carbon::parse($request->end_time),
                ]);
            }

            DB::commit();

            // Send notification if status changed
            if ($request->has('status') && $request->status !== $oldStatus) {
                $appointment->patient->notify(new AppointmentUpdated($appointment));
            }

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous mis à jour avec succès',
                'data' => new AppointmentResource($appointment->fresh(['patient', 'slot.kine']))
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Appointment update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du rendez-vous'
            ], 500);
        }
    }

    /**
     * Cancel appointment
     */
    public function cancel(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('cancel', $appointment);

        $validator = Validator::make($request->all(), [
            'cancellation_reason_id' => 'required|exists:cancellation_reasons,id',
            'additional_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if appointment can be cancelled
        if ($appointment->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Le rendez-vous est déjà annulé'
            ], 400);
        }

        // Check cancellation policy (24 hours before)
        $hoursUntilAppointment = now()->diffInHours($appointment->slot->start_time, false);
        if ($hoursUntilAppointment < 24 && $hoursUntilAppointment > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Les annulations doivent être faites au moins 24h à l\'avance'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $oldStatus = $appointment->status;
            $appointment->status = 'cancelled';
            $appointment->save();

            // Store cancellation reason
            $appointment->cancellationReasons()->create([
                'cancellation_reason_id' => $request->cancellation_reason_id,
                'additional_notes' => $request->additional_notes,
                'requested_by_patient' => Auth::user()->hasRole('patient'),
            ]);

            // Make slot available again
            $appointment->slot()->update(['is_available' => true]);

            DB::commit();

            // Send notifications
            $appointment->patient->notify(new AppointmentCancelled($appointment));
            $appointment->slot->kine->notify(new AppointmentCancelled($appointment));

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous annulé avec succès',
                'data' => new AppointmentResource($appointment->fresh(['patient', 'slot.kine']))
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Appointment cancellation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation du rendez-vous'
            ], 500);
        }
    }

    /**
     * Confirm appointment (by kine)
     */
    public function confirm(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('confirm', $appointment);

        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:0',
            'video_link' => 'nullable|required_if:is_online,true|url',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($appointment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous en attente peuvent être confirmés'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $appointment->update([
                'status' => 'confirmed',
                'price' => $request->price,
                'video_link' => $appointment->is_online ? $request->video_link : null,
                'notes' => $request->notes ?: $appointment->notes,
            ]);

            DB::commit();

            // Send confirmation notification to patient
            $appointment->patient->notify(new AppointmentConfirmed($appointment));

            return response()->json([
                'success' => true,
                'message' => 'Rendez-vous confirmé avec succès',
                'data' => new AppointmentResource($appointment->fresh(['patient', 'slot.kine']))
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Appointment confirmation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la confirmation du rendez-vous'
            ], 500);
        }
    }

    /**
     * Complete appointment
     */
    public function complete(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->authorize('complete', $appointment);

        $validator = Validator::make($request->all(), [
            'summary' => 'nullable|string|max:1000',
            'prescription' => 'nullable|string|max:2000',
            'next_session_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($appointment->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous confirmés peuvent être complétés'
            ], 400);
        }

        $appointment->update([
            'status' => 'completed',
            'summary' => $request->summary,
            'prescription' => $request->prescription,
            'next_session_notes' => $request->next_session_notes,
            'completed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rendez-vous marqué comme complété',
            'data' => new AppointmentResource($appointment->fresh(['patient', 'slot.kine']))
        ]);
    }

    /**
     * Get cancellation reasons
     */
    public function cancellationReasons()
    {
        $reasons = CancellationReason::where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reasons
        ]);
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

        $query = Appointment::query();

        if ($user->hasRole('kine')) {
            $query->whereHas('slot', function ($q) use ($user) {
                $q->where('kine_id', $user->id);
            });
        } elseif ($user->hasRole('patient')) {
            $query->where('patient_id', $user->id);
        }

        $query->whereBetween('created_at', [$startDate, $endDate]);

        $total = $query->count();
        $pending = clone $query;
        $confirmed = clone $query;
        $completed = clone $query;
        $cancelled = clone $query;

        $stats = [
            'total' => $total,
            'pending' => $pending->where('status', 'pending')->count(),
            'confirmed' => $confirmed->where('status', 'confirmed')->count(),
            'completed' => $completed->where('status', 'completed')->count(),
            'cancelled' => $cancelled->where('status', 'cancelled')->count(),
            'revenue' => $query->whereIn('status', ['completed', 'confirmed'])
                ->sum('price') ?? 0,
            'by_type' => $query->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'period' => $period,
            'period_start' => $startDate->toDateString(),
            'period_end' => $endDate->toDateString(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request)
    {
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('kine_id')) {
            $query->whereHas('slot', function ($q) use ($request) {
                $q->where('kine_id', $request->kine_id);
            });
        }

        if ($request->has('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereHas('slot', function ($q) use ($request) {
                $q->whereBetween('start_time', [
                    $request->start_date,
                    $request->end_date
                ]);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($q) use ($search) {
                      $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('slot.kine', function ($q) use ($search) {
                      $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }
    }

    /**
     * Handle appointment status changes
     */
    private function handleStatusChange($appointment, $oldStatus, $newStatus)
    {
        // When kine confirms appointment
        if ($oldStatus === 'pending' && $newStatus === 'confirmed') {
            // Send confirmation email/SMS to patient
        }

        // When appointment is cancelled
        if ($newStatus === 'cancelled') {
            // Make slot available again
            $appointment->slot()->update(['is_available' => true]);

            // Send cancellation notifications
        }
    }
}
