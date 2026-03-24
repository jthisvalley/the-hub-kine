<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\PatientGoal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PatientGoalController extends Controller
{
    /**
     * Get all goals for a specific patient
     */
    public function index(Request $request, $patientId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $query = PatientGoal::where('patient_id', $patientId)
            ->with(['kine' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'avatar_url');
            }])
            ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Paginate results
        $perPage = $request->per_page ?? 20;
        $goals = $query->paginate($perPage);

        // Transform data for frontend
        $transformedGoals = $goals->map(function ($goal) {
            return $this->transformGoal($goal);
        });

        return response()->json([
            'success' => true,
            'data' => $transformedGoals,
            'meta' => [
                'current_page' => $goals->currentPage(),
                'last_page' => $goals->lastPage(),
                'per_page' => $goals->perPage(),
                'total' => $goals->total(),
            ],
        ]);
    }

    /**
     * Get a single goal
     */
    public function show(Request $request, $goalId)
    {
        $kine = Auth::user();

        $goal = PatientGoal::with(['kine' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'avatar_url');
            }])
            ->where('id', $goalId)
            ->firstOrFail();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $goal->patient_id)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->transformGoal($goal),
        ]);
    }

    /**
     * Create a new goal for a patient
     */
    public function store(Request $request, $patientId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'metric_type' => 'required|in:pain_level,mobility_score,adherence_rate,strength_score,flexibility_score,session_count',
            'target_value' => 'required|numeric|min:0',
            'unit' => 'required|string|max:20',
            'deadline' => 'required|date|after:today',
            'current_value' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $currentValue = $request->current_value ?? 0;
            $progress = $request->target_value > 0
                ? min(100, ($currentValue / $request->target_value) * 100)
                : 0;

            $goal = PatientGoal::create([
                'patient_id' => $patientId,
                'kine_id' => $kine->id,
                'title' => $request->title,
                'description' => $request->description,
                'metric_type' => $request->metric_type,
                'target_value' => $request->target_value,
                'current_value' => $currentValue,
                'unit' => $request->unit,
                'deadline' => $request->deadline,
                'progress_percentage' => $progress,
                'status' => $this->determineStatus($progress, $request->deadline),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->load(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Objectif créé avec succès'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'objectif',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a goal
     */
    public function update(Request $request, $goalId)
    {
        $kine = Auth::user();

        $goal = PatientGoal::where('id', $goalId)->firstOrFail();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $goal->patient_id)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'metric_type' => 'sometimes|in:pain_level,mobility_score,adherence_rate,strength_score,flexibility_score,session_count',
            'target_value' => 'sometimes|numeric|min:0',
            'current_value' => 'sometimes|numeric|min:0',
            'unit' => 'sometimes|string|max:20',
            'deadline' => 'sometimes|date',
            'status' => 'sometimes|in:in_progress,completed,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $updateData = [];

            if ($request->has('title')) $updateData['title'] = $request->title;
            if ($request->has('description')) $updateData['description'] = $request->description;
            if ($request->has('metric_type')) $updateData['metric_type'] = $request->metric_type;
            if ($request->has('target_value')) $updateData['target_value'] = $request->target_value;
            if ($request->has('current_value')) $updateData['current_value'] = $request->current_value;
            if ($request->has('unit')) $updateData['unit'] = $request->unit;
            if ($request->has('deadline')) $updateData['deadline'] = $request->deadline;

            // Recalculate progress if target or current value changed
            if ($request->has('target_value') || $request->has('current_value')) {
                $targetValue = $request->target_value ?? $goal->target_value;
                $currentValue = $request->current_value ?? $goal->current_value;

                $updateData['progress_percentage'] = $targetValue > 0
                    ? min(100, ($currentValue / $targetValue) * 100)
                    : 0;

                $updateData['status'] = $this->determineStatus(
                    $updateData['progress_percentage'],
                    $request->deadline ?? $goal->deadline
                );
            }

            $goal->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->fresh(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Objectif mis à jour avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'objectif',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update goal progress (increment/decrement)
     */
    public function updateProgress(Request $request, $goalId)
    {
        $kine = Auth::user();

        $goal = PatientGoal::where('id', $goalId)->firstOrFail();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $goal->patient_id)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'current_value' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $currentValue = $request->current_value;
            $progress = $goal->target_value > 0
                ? min(100, ($currentValue / $goal->target_value) * 100)
                : 0;

            $goal->update([
                'current_value' => $currentValue,
                'progress_percentage' => $progress,
                'status' => $this->determineStatus($progress, $goal->deadline),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->fresh(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Progression mise à jour avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la progression',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a goal
     */
    public function destroy($goalId)
    {
        $kine = Auth::user();

        $goal = PatientGoal::where('id', $goalId)->firstOrFail();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $goal->patient_id)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $goal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Objectif supprimé avec succès'
        ]);
    }

    /**
     * Get goal statistics for a patient
     */
    public function statistics($patientId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $totalGoals = PatientGoal::where('patient_id', $patientId)->count();
        $completedGoals = PatientGoal::where('patient_id', $patientId)
            ->where('status', 'completed')
            ->count();
        $inProgressGoals = PatientGoal::where('patient_id', $patientId)
            ->where('status', 'in_progress')
            ->count();
        $failedGoals = PatientGoal::where('patient_id', $patientId)
            ->where('status', 'failed')
            ->count();

        $averageProgress = PatientGoal::where('patient_id', $patientId)
            ->avg('progress_percentage') ?? 0;

        // Goals by metric type
        $goalsByType = PatientGoal::where('patient_id', $patientId)
            ->select(
                'metric_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(progress_percentage) as avg_progress')
            )
            ->groupBy('metric_type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $totalGoals,
                'completed' => $completedGoals,
                'in_progress' => $inProgressGoals,
                'failed' => $failedGoals,
                'average_progress' => round($averageProgress, 1),
                'by_type' => $goalsByType,
                'completion_rate' => $totalGoals > 0
                    ? round(($completedGoals / $totalGoals) * 100, 1)
                    : 0,
            ],
        ]);
    }

    /**
     * Determine goal status based on progress and deadline
     */
    private function determineStatus($progress, $deadline)
    {
        $deadlineDate = Carbon::parse($deadline);

        if ($progress >= 100) {
            return 'completed';
        }

        if ($deadlineDate->isPast()) {
            return 'failed';
        }

        return 'in_progress';
    }

    /**
     * Map metric type to goal type for frontend compatibility
     */
    private function mapMetricTypeToGoalType($metricType)
    {
        $mapping = [
            'pain_level' => 'pain',
            'mobility_score' => 'mobility',
            'adherence_rate' => 'adherence',
            'strength_score' => 'strength',
            'flexibility_score' => 'flexibility',
            'session_count' => 'exercises',
        ];

        return $mapping[$metricType] ?? 'general';
    }

    /**
     * Transform goal for API response
     */
    private function transformGoal($goal)
    {
        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'description' => $goal->description,
            'type' => $this->mapMetricTypeToGoalType($goal->metric_type),
            'metricType' => $goal->metric_type,
            'targetValue' => (float) $goal->target_value,
            'currentValue' => (float) $goal->current_value,
            'deadline' => $goal->deadline->format('Y-m-d'),
            'progress' => $goal->progress_percentage,
            'status' => $goal->status,
            'unit' => $goal->unit,
            'createdAt' => $goal->created_at->format('Y-m-d'),
            'kine' => $goal->kine ? [
                'name' => trim($goal->kine->first_name . ' ' . $goal->kine->last_name),
                'avatar' => $goal->kine->avatar_url,
            ] : null,
        ];
    }
}
