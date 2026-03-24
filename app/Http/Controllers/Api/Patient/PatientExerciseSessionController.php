<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\ExerciseSession;
use App\Models\PatientProgramAssignment;
use App\Models\Exercise;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PatientExerciseSessionController extends Controller
{
    /**
     * Get today's exercise sessions
     */
    public function getTodaySessions(Request $request)
    {
        $patient = Auth::user();
        $today = Carbon::today();

        $query = ExerciseSession::with([
            'exercise',
            'exercise.category',
            'assignment.program'
        ])->where('patient_id', $patient->id)
          ->whereDate('session_date', $today);

        if ($request->has('program_assignment_id')) {
            $query->where('program_assignment_id', $request->program_assignment_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', ['scheduled', 'completed']);
        }

        $query->orderBy('session_time');

        $perPage = $request->per_page ?? 10;
        $sessions = $query->paginate($perPage);

        $data = $sessions->map(function ($session) {
            return $this->formatSession($session);
        });

        $todayStats = [
            'total_sessions' => $query->count(),
            'completed_sessions' => $query->where('status', 'completed')->count(),
            'pending_sessions' => $query->where('status', 'scheduled')->count(),
            'average_pain_level' => $query->where('status', 'completed')->avg('pain_level'),
            'completion_rate' => $query->count() > 0
                ? ($query->where('status', 'completed')->count() / $query->count()) * 100
                : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'today_stats' => $todayStats,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'from' => $sessions->firstItem(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'to' => $sessions->lastItem(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Get session history
     */
    public function getHistory(Request $request)
    {
        $patient = Auth::user();

        $query = ExerciseSession::with([
            'exercise',
            'exercise.category',
            'assignment.program',
            'checkIns'
        ])->where('patient_id', $patient->id);

        if ($request->has('start_date')) {
            $query->whereDate('session_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('session_date', '<=', $request->end_date);
        }

        if ($request->has('program_assignment_id')) {
            $query->where('program_assignment_id', $request->program_assignment_id);
        }

        if ($request->has('exercise_id')) {
            $query->where('exercise_id', $request->exercise_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('session_date', 'desc')
              ->orderBy('session_time', 'desc');

        $perPage = $request->per_page ?? 20;
        $sessions = $query->paginate($perPage);

        $data = $sessions->map(function ($session) {
            return $this->formatSession($session, true);
        });

        $stats = [
            'total_sessions' => $query->count(),
            'completed_sessions' => $query->where('status', 'completed')->count(),
            'average_pain_level' => $query->where('status', 'completed')->avg('pain_level'),
            'completion_rate' => $query->count() > 0
                ? ($query->where('status', 'completed')->count() / $query->count()) * 100
                : 0,
            'total_duration' => $query->sum('duration_minutes'),
            'average_duration' => $query->avg('duration_minutes'),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'stats' => $stats,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'from' => $sessions->firstItem(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'to' => $sessions->lastItem(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Get upcoming sessions
     */
    public function getUpcoming(Request $request)
    {
        $patient = Auth::user();
        $today = Carbon::today();
        $daysAhead = $request->days_ahead ?? 7;
        $futureDate = Carbon::today()->addDays($daysAhead);

        $query = ExerciseSession::with([
            'exercise',
            'exercise.category',
            'assignment.program'
        ])->where('patient_id', $patient->id)
          ->whereBetween('session_date', [$today, $futureDate])
          ->where('status', 'scheduled')
          ->orderBy('session_date')
          ->orderBy('session_time');

        $perPage = $request->per_page ?? 10;
        $sessions = $query->paginate($perPage);

        $groupedSessions = $sessions->groupBy(function ($session) {
            return Carbon::parse($session->session_date)->format('Y-m-d');
        });

        return response()->json([
            'success' => true,
            'data' => $groupedSessions,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'from' => $sessions->firstItem(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'to' => $sessions->lastItem(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Create exercise session check-in
     */
    public function checkIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exercise_id' => 'required|exists:exercises,id',
            'program_assignment_id' => 'required|exists:patient_program_assignments,id',
            'actual_repetitions' => 'required|integer|min:1',
            'pain_level' => 'required|integer|min:1|max:10',
            'difficulty' => 'required|in:easy,normal,hard,very_hard',
            'comments' => 'nullable|string|max:500',
            'duration_minutes' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $patient = Auth::user();

        $assignment = PatientProgramAssignment::where('id', $request->program_assignment_id)
            ->where('patient_id', $patient->id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'Program assignment not found or access denied'
            ], 404);
        }

        $exerciseExists = Exercise::where('id', $request->exercise_id)
            ->where('program_id', $assignment->program_id)
            ->exists();

        if (!$exerciseExists) {
            return response()->json([
                'success' => false,
                'message' => 'Exercise not found in this program'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $session = ExerciseSession::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'program_assignment_id' => $assignment->id,
                    'exercise_id' => $request->exercise_id,
                    'session_date' => today(),
                ],
                [
                    'session_time' => now(),
                    'planned_repetitions' => $request->actual_repetitions,
                    'actual_repetitions' => $request->actual_repetitions,
                    'pain_level' => $request->pain_level,
                    'difficulty' => $request->difficulty,
                    'comments' => $request->comments,
                    'duration_minutes' => $request->duration_minutes ?? 5,
                    'completed_at' => now(),
                    'status' => 'completed',
                ]
            );

            $checkIn = CheckIn::create([
                'patient_id' => $patient->id,
                'exercise_session_id' => $session->id,
                'completed_at' => now(),
                'pain_level' => $request->pain_level,
                'notes' => $request->comments,
                'duration_seconds' => ($request->duration_minutes ?? 5) * 60,
            ]);

            $pointsEarned = $this->updatePatientPoints($patient, $session);

            // Send notification to kine about completed exercise
            $kineId = $assignment->program->kine_id;
            if ($kineId) {
                $this->createExerciseNotification(
                    $kineId,
                    'exercise.completed',
                    'Exercice complété',
                    "Le patient {$patient->first_name} {$patient->last_name} a complété l'exercice",
                    NotificationPriority::LOW,
                    [
                        'patient_id' => $patient->id,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                        'exercise_id' => $request->exercise_id,
                        'session_id' => $session->id,
                        'pain_level' => $request->pain_level,
                        'difficulty' => $request->difficulty,
                    ],
                    "/kine/patients/{$patient->id}/progress"
                );
            }

            // Send notification to patient about points earned
            if ($pointsEarned > 0) {
                $this->createExerciseNotification(
                    $patient->id,
                    'exercise.points_earned',
                    'Points gagnés',
                    "Vous avez gagné {$pointsEarned} points pour avoir complété cet exercice!",
                    NotificationPriority::LOW,
                    [
                        'points' => $pointsEarned,
                        'exercise_id' => $request->exercise_id,
                        'session_id' => $session->id,
                    ],
                    "/patient/progress"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Session check-in recorded successfully',
                'data' => [
                    'session' => $session,
                    'check_in' => $checkIn,
                    'points_earned' => $pointsEarned,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to record session: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update exercise session
     */
    public function update(Request $request, $sessionId)
    {
        $validator = Validator::make($request->all(), [
            'actual_repetitions' => 'nullable|integer|min:1',
            'pain_level' => 'nullable|integer|min:1|max:10',
            'difficulty' => 'nullable|in:easy,normal,hard,very_hard',
            'comments' => 'nullable|string|max:500',
            'duration_minutes' => 'nullable|integer|min:1',
            'status' => 'nullable|in:completed,skipped',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $patient = Auth::user();

        $session = ExerciseSession::where('id', $sessionId)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        $updateData = [];
        if ($request->has('actual_repetitions')) {
            $updateData['actual_repetitions'] = $request->actual_repetitions;
        }
        if ($request->has('pain_level')) {
            $updateData['pain_level'] = $request->pain_level;
        }
        if ($request->has('difficulty')) {
            $updateData['difficulty'] = $request->difficulty;
        }
        if ($request->has('comments')) {
            $updateData['comments'] = $request->comments;
        }
        if ($request->has('duration_minutes')) {
            $updateData['duration_minutes'] = $request->duration_minutes;
        }
        if ($request->has('status')) {
            $updateData['status'] = $request->status;
            if ($request->status === 'completed') {
                $updateData['completed_at'] = now();
            }
        }

        $session->update($updateData);

        if ($request->has('pain_level') || $request->has('comments')) {
            CheckIn::create([
                'patient_id' => $patient->id,
                'exercise_session_id' => $session->id,
                'completed_at' => now(),
                'pain_level' => $request->pain_level ?? $session->pain_level,
                'notes' => $request->comments ?? $session->comments,
                'duration_seconds' => ($request->duration_minutes ?? $session->duration_minutes) * 60,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Session updated successfully',
            'data' => $session->load('exercise', 'checkIns'),
        ]);
    }

    /**
     * Mark exercise as complete
     */
    public function markComplete($sessionId)
    {
        $patient = Auth::user();

        $session = ExerciseSession::where('id', $sessionId)
            ->where('patient_id', $patient->id)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $exercise = Exercise::find($session->exercise_id);

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_repetitions' => $exercise->reps,
            'planned_repetitions' => $exercise->reps,
        ]);

        $pointsEarned = $this->updatePatientPoints($patient, $session);

        // Send notification to kine
        $assignment = $session->assignment;
        if ($assignment && $assignment->program) {
            $this->createExerciseNotification(
                $assignment->program->kine_id,
                'exercise.completed',
                'Exercice complété',
                "Le patient {$patient->first_name} {$patient->last_name} a complété l'exercice: {$exercise->name}",
                NotificationPriority::LOW,
                [
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'exercise_id' => $exercise->id,
                    'exercise_name' => $exercise->name,
                    'session_id' => $session->id,
                ],
                "/kine/patients/{$patient->id}/progress"
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Exercise marked as complete',
            'data' => [
                'session' => $session,
                'points_earned' => $pointsEarned,
            ],
        ]);
    }

    /**
     * Helper method to format session
     */
    private function formatSession($session, $includeCheckIns = false)
    {
        $formatted = [
            'id' => $session->id,
            'patient_id' => $session->patient_id,
            'program_assignment_id' => $session->program_assignment_id,
            'exercise_id' => $session->exercise_id,
            'session_date' => $session->session_date?->format('Y-m-d'),
            'session_time' => $session->session_time?->format('H:i:s'),
            'planned_repetitions' => $session->planned_repetitions,
            'actual_repetitions' => $session->actual_repetitions,
            'pain_level' => $session->pain_level,
            'difficulty' => $session->difficulty,
            'comments' => $session->comments,
            'duration_minutes' => $session->duration_minutes,
            'completed_at' => $session->completed_at?->format('Y-m-d H:i:s'),
            'status' => $session->status,
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
            'exercise' => $session->exercise ? [
                'id' => $session->exercise->id,
                'name' => $session->exercise->name,
                'description' => $session->exercise->description,
                'video_url' => $session->exercise->video_url,
                'duration_seconds' => $session->exercise->duration_seconds,
                'reps' => $session->exercise->reps,
                'sets' => $session->exercise->sets,
                'rest_seconds' => $session->exercise->rest_seconds,
                'difficulty' => $session->exercise->difficulty,
                'muscle_groups' => $session->exercise->muscle_groups,
                'instructions' => $session->exercise->instructions,
                'category' => $session->exercise->category ? [
                    'id' => $session->exercise->category->id,
                    'name' => $session->exercise->category->name,
                ] : null,
            ] : null,
            'assignment' => $session->assignment ? [
                'id' => $session->assignment->id,
                'program' => $session->assignment->program ? [
                    'id' => $session->assignment->program->id,
                    'name' => $session->assignment->program->name,
                ] : null,
            ] : null,
        ];

        if ($includeCheckIns && $session->checkIns) {
            $formatted['check_ins'] = $session->checkIns->map(function ($checkIn) {
                return [
                    'id' => $checkIn->id,
                    'pain_level' => $checkIn->pain_level,
                    'notes' => $checkIn->notes,
                    'duration_seconds' => $checkIn->duration_seconds,
                    'completed_at' => $checkIn->completed_at?->format('Y-m-d H:i:s'),
                ];
            });
        }

        return $formatted;
    }

    /**
     * Helper method to update patient points
     */
    private function updatePatientPoints($patient, $session)
    {
        $pointsEarned = 10;

        if ($session->pain_level <= 3) {
            $pointsEarned += 5;
        } elseif ($session->pain_level <= 5) {
            $pointsEarned += 3;
        } elseif ($session->pain_level <= 7) {
            $pointsEarned += 1;
        }

        return $pointsEarned;
    }

    /**
     * Helper method to create exercise notifications
     */
    private function createExerciseNotification($userId, $type, $title, $message, $priority, $metadata = null, $actionUrl = null)
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
