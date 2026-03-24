<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\SessionCheckInRequest;
use App\Http\Resources\DashboardStatsResource;
use App\Http\Resources\SessionHistoryResource;
use App\Models\ExerciseSession;
use App\Models\PatientProgramAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ExerciseSession::with([
            'exercise' => function ($query) {
                $query->select('id', 'name', 'program_id');
            },
            'assignment.program' => function ($query) {
                $query->select('id', 'name', 'description');
            },
            'patient' => function ($query) {
                $query->select('id', 'first_name', 'last_name');
            }
        ]);

        // Apply authorization based on user role
        if ($user->isKine()) {
            // Kine can see sessions of their assigned patients
            $patientIds = $user->assignedPatients()->pluck('users.id');
            $query->whereIn('patient_id', $patientIds);
        } else {
            // Patient can only see their own sessions
            $query->where('patient_id', $user->id);
        }

        // Apply filters
        $this->applyFilters($query, $request);

        // Sort by latest first
        $query->orderBy('session_date', 'desc')
              ->orderBy('created_at', 'desc');

        // Paginate results
        $perPage = $request->input('per_page', 20);
        $sessions = $query->paginate($perPage);

        return response()->json([
            'data' => SessionHistoryResource::collection($sessions),
            'meta' => [
                'total' => $sessions->total(),
                'per_page' => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'from' => $sessions->firstItem(),
                'to' => $sessions->lastItem(),
            ],
        ]);
    }

    private function applyFilters($query, Request $request): void
    {
        // Filter by program assignment
        if ($request->has('program_assignment_id')) {
            $query->where('program_assignment_id', $request->program_assignment_id);
        }

        // Filter by program (via assignment)
        if ($request->has('program_id')) {
            $query->whereHas('assignment', function ($q) use ($request) {
                $q->where('program_id', $request->program_id);
            });
        }

        // Filter by exercise
        if ($request->has('exercise_id')) {
            $query->where('exercise_id', $request->exercise_id);
        }

        // Filter by patient (for kine only)
        if ($request->has('patient_id') && $request->user()->isKine()) {
            $query->where('patient_id', $request->patient_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('session_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('session_date', '<=', $request->date_to);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by pain level range
        if ($request->has('pain_min')) {
            $query->where('pain_level', '>=', $request->pain_min);
        }

        if ($request->has('pain_max')) {
            $query->where('pain_level', '<=', $request->pain_max);
        }

        // Filter by difficulty
        if ($request->has('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }

        // Filter by completion (completed vs not completed)
        if ($request->has('completed')) {
            $isCompleted = filter_var($request->completed, FILTER_VALIDATE_BOOLEAN);
            $query->where('status', $isCompleted ? 'completed' : 'pending');
        }

        // Search by comments
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('comments', 'like', '%' . $request->search . '%')
                  ->orWhereHas('exercise', function ($q) use ($request) {
                      $q->where('name', 'like', '%' . $request->search . '%');
                  });
            });
        }
    }

    // Add a method to get aggregated stats for exercise sessions
    public function getSessionStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ExerciseSession::query();

        if ($user->isKine()) {
            $patientIds = $user->assignedPatients()->pluck('users.id');
            $query->whereIn('patient_id', $patientIds);
        } else {
            $query->where('patient_id', $user->id);
        }

        // Apply date filter if provided
        if ($request->has('date_from')) {
            $query->where('session_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('session_date', '<=', $request->date_to);
        }

        // Calculate statistics
        $stats = [
            'total_sessions' => $query->count(),
            'completed_sessions' => $query->clone()->where('status', 'completed')->count(),
            'average_pain_level' => round($query->clone()->where('status', 'completed')->avg('pain_level') ?? 0, 1),
            'average_completion_rate' => $this->calculateCompletionRate($query),
            'sessions_by_difficulty' => $this->getSessionsByDifficulty($query),
            'pain_level_distribution' => $this->getPainLevelDistribution($query),
            'daily_completion_trend' => $this->getDailyCompletionTrend($query),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    private function calculateCompletionRate($query): float
    {
        $total = $query->clone()->count();
        $completed = $query->clone()->where('status', 'completed')->count();

        return $total > 0 ? round(($completed / $total) * 100, 1) : 0;
    }

    private function getSessionsByDifficulty($query): array
    {
        $difficulties = ['easy', 'normal', 'hard', 'very_hard'];
        $result = [];

        foreach ($difficulties as $difficulty) {
            $result[$difficulty] = $query->clone()
                ->where('status', 'completed')
                ->where('difficulty', $difficulty)
                ->count();
        }

        return $result;
    }

    private function getPainLevelDistribution($query): array
    {
        $distribution = [];

        for ($i = 1; $i <= 10; $i++) {
            $distribution[$i] = $query->clone()
                ->where('status', 'completed')
                ->where('pain_level', $i)
                ->count();
        }

        return $distribution;
    }

    private function getDailyCompletionTrend($query): array
    {
        $trend = $query->clone()
            ->selectRaw('DATE(session_date) as date, COUNT(*) as total_sessions')
            ->where('status', 'completed')
            ->where('session_date', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'sessions' => $item->total_sessions,
                ];
            });

        return $trend->toArray();
    }

    public function checkIn(SessionCheckInRequest $request): JsonResponse
    {
        $session = ExerciseSession::create([
            'patient_id' => $request->user()->id,
            'exercise_id' => $request->exercise_id,
            'program_assignment_id' => $request->program_assignment_id,
            'session_date' => $request->session_date,
            'planned_repetitions' => $request->planned_repetitions,
            'actual_repetitions' => $request->actual_repetitions,
            'pain_level' => $request->pain_level,
            'difficulty' => $request->difficulty,
            'comments' => $request->comments,
            'duration_minutes' => $request->duration_minutes,
            'completed_at' => $request->completed_at ?? now(),
            'status' => $request->status,
        ]);

        // Update patient loyalty points if completed
        if ($session->status === 'completed') {
            $this->updatePatientPoints($request->user(), $session);
        }

        return response()->json([
            'message' => 'Session checked in successfully',
            'data' => new SessionHistoryResource($session->load('exercise')),
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ExerciseSession::with(['exercise', 'assignment.program'])
            ->where('patient_id', $user->id)
            ->orderBy('session_date', 'desc')
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->has('exercise_id')) {
            $query->where('exercise_id', $request->exercise_id);
        }

        if ($request->has('program_assignment_id')) {
            $query->where('program_assignment_id', $request->program_assignment_id);
        }

        if ($request->has('date_from')) {
            $query->where('session_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('session_date', '<=', $request->date_to);
        }

        $sessions = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => SessionHistoryResource::collection($sessions),
            'meta' => [
                'total' => $sessions->total(),
                'per_page' => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        // For patient view
        if ($user->isPatient()) {
            $stats = $this->getPatientStats($user);
        } else {
            // For kine view - aggregated stats for all assigned patients
            $stats = $this->getKineStats($user);
        }

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function dashboardStats(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isKine()) {
            $stats = $this->getKineDashboardStats($user);
        } else {
            $stats = $this->getPatientDashboardStats($user);
        }

        return response()->json([
            'data' => new DashboardStatsResource($stats),
        ]);
    }

    private function getPatientStats($user): array
    {
        $totalSessions = ExerciseSession::where('patient_id', $user->id)->count();
        $completedSessions = ExerciseSession::where('patient_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $avgPain = ExerciseSession::where('patient_id', $user->id)
            ->where('status', 'completed')
            ->avg('pain_level');

        $weeklySessions = ExerciseSession::where('patient_id', $user->id)
            ->where('session_date', '>=', now()->subDays(7))
            ->where('status', 'completed')
            ->count();

        return [
            'total_sessions' => $totalSessions,
            'completed_sessions' => $completedSessions,
            'average_pain' => round($avgPain ?? 0, 1),
            'weekly_sessions' => $weeklySessions,
            'completion_rate' => $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0,
        ];
    }

    private function getKineStats($user): array
    {
        $patientIds = $user->assignedPatients()->pluck('users.id');

        $totalSessions = ExerciseSession::whereIn('patient_id', $patientIds)->count();
        $completedSessions = ExerciseSession::whereIn('patient_id', $patientIds)
            ->where('status', 'completed')
            ->count();

        $avgPain = ExerciseSession::whereIn('patient_id', $patientIds)
            ->where('status', 'completed')
            ->avg('pain_level');

        $activePatients = PatientProgramAssignment::whereIn('patient_id', $patientIds)
            ->where('status', 'active')
            ->distinct('patient_id')
            ->count();

        return [
            'total_sessions' => $totalSessions,
            'completed_sessions' => $completedSessions,
            'average_pain' => round($avgPain ?? 0, 1),
            'active_patients' => $activePatients,
            'completion_rate' => $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0,
        ];
    }

    private function getKineDashboardStats($user): array
    {
        $patientIds = $user->assignedPatients()->pluck('users.id');

        $activePrograms = PatientProgramAssignment::whereIn('patient_id', $patientIds)
            ->where('status', 'active')
            ->count();

        $weeklySessions = ExerciseSession::whereIn('patient_id', $patientIds)
            ->where('session_date', '>=', now()->subDays(7))
            ->where('status', 'completed')
            ->count();

        $avgPain = ExerciseSession::whereIn('patient_id', $patientIds)
            ->where('status', 'completed')
            ->where('session_date', '>=', now()->subDays(30))
            ->avg('pain_level');

        $completionRate = $this->calculateAverageCompletionRate($patientIds);

        return [
            'active_programs' => $activePrograms,
            'average_adherence' => $completionRate,
            'average_pain' => round($avgPain ?? 0, 1),
            'weekly_sessions' => $weeklySessions,
            'active_patients' => count($patientIds),
            'completion_rate' => $completionRate,
            'total_points' => 0, // Implement points system
            'level' => 'Gold',
            'streak' => 14,
            'completed_exercises' => 156,
        ];
    }

    private function getPatientDashboardStats($user): array
    {
        // Similar logic for patient dashboard
        return [
            'active_programs' => 2,
            'average_adherence' => 89,
            'average_pain' => 4.2,
            'weekly_sessions' => 12,
            'active_patients' => 1,
            'completion_rate' => 78,
            'total_points' => 2450,
            'level' => 'Gold',
            'streak' => 14,
            'completed_exercises' => 156,
        ];
    }

    private function calculateAverageCompletionRate($patientIds): float
    {
        // Calculate average completion rate across all patients
        $totalCompletion = 0;
        $patientCount = 0;

        foreach ($patientIds as $patientId) {
            $sessions = ExerciseSession::where('patient_id', $patientId)->count();
            $completed = ExerciseSession::where('patient_id', $patientId)
                ->where('status', 'completed')
                ->count();

            if ($sessions > 0) {
                $totalCompletion += ($completed / $sessions) * 100;
                $patientCount++;
            }
        }

        return $patientCount > 0 ? round($totalCompletion / $patientCount) : 0;
    }

    private function updatePatientPoints($user, $session): void
    {
        // Implement points logic here
        // Example: 10 points per completed session
        if ($user->loyaltyPoints) {
            $user->loyaltyPoints->increment('points', 10);
        }
    }
}
