<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientGoal;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PatientGoalController extends Controller
{
    /**
     * Get patient goals
     */
    public function index(Request $request)
    {
        $patient = Auth::user();

        $query = PatientGoal::where('patient_id', $patient->id)
            ->with(['kine' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'avatar_url');
            }])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->per_page ?? 10;
        $goals = $query->paginate($perPage);

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
     * Create a new goal
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'metric_type' => 'required|in:pain_level,mobility_score,adherence_rate,strength_score,flexibility_score,session_count',
            'target_value' => 'required|numeric|min:0',
            'unit' => 'required|string|max:20',
            'deadline' => 'required|date|after:today',
            'kine_id' => 'nullable|exists:users,id',
            'current_value' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $patient = Auth::user();

        DB::beginTransaction();

        try {
            $currentValue = $request->current_value ?? 0;
            $progress = $request->target_value > 0
                ? min(100, ($currentValue / $request->target_value) * 100)
                : 0;

            $goal = PatientGoal::create([
                'patient_id' => $patient->id,
                'kine_id' => $request->kine_id ?? $patient->assigned_kine_id,
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

            // Notify kine about new goal
            if ($goal->kine_id) {
                $this->createGoalNotification(
                    $goal->kine_id,
                    'goal.created',
                    'Nouvel objectif créé',
                    "Le patient {$patient->first_name} {$patient->last_name} a créé un nouvel objectif: {$goal->title}",
                    NotificationPriority::MEDIUM,
                    [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'patient_id' => $patient->id,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                        'target_value' => $goal->target_value,
                        'deadline' => $goal->deadline->toISOString(),
                    ],
                    "/kine/patients/{$patient->id}/goals/{$goal->id}"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->load(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Goal created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating goal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a goal
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'metric_type' => 'sometimes|in:pain_level,mobility_score,adherence_rate,strength_score,flexibility_score,session_count',
            'target_value' => 'sometimes|numeric|min:0',
            'current_value' => 'sometimes|numeric|min:0',
            'unit' => 'sometimes|string|max:20',
            'deadline' => 'sometimes|date',
            'status' => 'sometimes|in:completed,in_progress,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $patient = Auth::user();
        $goal = PatientGoal::where('patient_id', $patient->id)
            ->where('id', $id)
            ->first();

        if (!$goal) {
            return response()->json([
                'success' => false,
                'message' => 'Goal not found'
            ], 404);
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

            $oldProgress = $goal->progress_percentage;
            $oldStatus = $goal->status;

            if ($request->has('current_value') || $request->has('target_value')) {
                $currentValue = $request->has('current_value') ? $request->current_value : $goal->current_value;
                $targetValue = $request->has('target_value') ? $request->target_value : $goal->target_value;

                if ($targetValue > 0) {
                    $progress = ($currentValue / $targetValue) * 100;
                    $updateData['progress_percentage'] = min(100, $progress);
                    $updateData['status'] = $this->determineStatus($progress, $request->deadline ?? $goal->deadline);
                }
            }

            $goal->update($updateData);

            // Notify kine about goal progress update
            if ($goal->kine_id && ($goal->progress_percentage != $oldProgress || $goal->status != $oldStatus)) {
                $this->createGoalNotification(
                    $goal->kine_id,
                    'goal.updated',
                    'Objectif mis à jour',
                    "Le patient {$patient->first_name} {$patient->last_name} a mis à jour son objectif: {$goal->title} ({$goal->progress_percentage}%)",
                    NotificationPriority::LOW,
                    [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'patient_id' => $patient->id,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                        'progress' => $goal->progress_percentage,
                        'status' => $goal->status,
                    ],
                    "/kine/patients/{$patient->id}/goals/{$goal->id}"
                );
            }

            // Notify patient if goal is completed
            if ($goal->status === 'completed' && $oldStatus !== 'completed') {
                $this->createGoalNotification(
                    $patient->id,
                    'goal.completed',
                    'Objectif atteint!',
                    "Félicitations! Vous avez atteint votre objectif: {$goal->title}",
                    NotificationPriority::HIGH,
                    [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'progress' => $goal->progress_percentage,
                    ],
                    "/patient/goals/{$goal->id}"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->fresh(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Goal updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating goal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a goal
     */
    public function destroy($id)
    {
        $patient = Auth::user();
        $goal = PatientGoal::where('patient_id', $patient->id)
            ->where('id', $id)
            ->first();

        if (!$goal) {
            return response()->json([
                'success' => false,
                'message' => 'Goal not found'
            ], 404);
        }

        $goal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Goal deleted successfully'
        ]);
    }

    /**
     * Update goal progress (from exercise completion, etc.)
     */
    public function updateProgress(Request $request, $id)
    {
        \Log::info('Updating goal progress', [
            'goal_id' => $id,
            'request_data' => $request->all(),
        ]);

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

        $patient = Auth::user();
        $goal = PatientGoal::where('patient_id', $patient->id)
            ->where('id', $id)
            ->first();

        if (!$goal) {
            return response()->json([
                'success' => false,
                'message' => 'Goal not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $oldProgress = $goal->progress_percentage;
            $oldStatus = $goal->status;

            $progress = $goal->target_value > 0
                ? min(100, ($request->current_value / $goal->target_value) * 100)
                : 0;

            $updateData = [
                'current_value' => $request->current_value,
                'progress_percentage' => $progress,
                'status' => $this->determineStatus($progress, $goal->deadline),
            ];

            $goal->update($updateData);

            // Notify kine about progress update
            if ($goal->kine_id && $goal->progress_percentage != $oldProgress) {
                $this->createGoalNotification(
                    $goal->kine_id,
                    'goal.progress_updated',
                    'Progression d\'objectif',
                    "Le patient {$patient->first_name} {$patient->last_name} a atteint {$goal->progress_percentage}% de son objectif: {$goal->title}",
                    NotificationPriority::LOW,
                    [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'patient_id' => $patient->id,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                        'progress' => $goal->progress_percentage,
                        'current_value' => $goal->current_value,
                        'target_value' => $goal->target_value,
                    ],
                    "/kine/patients/{$patient->id}/goals/{$goal->id}"
                );
            }

            // Notify patient if goal is completed
            if ($goal->status === 'completed' && $oldStatus !== 'completed') {
                $this->createGoalNotification(
                    $patient->id,
                    'goal.completed',
                    'Objectif atteint!',
                    "Félicitations! Vous avez atteint votre objectif: {$goal->title}",
                    NotificationPriority::HIGH,
                    [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'progress' => $goal->progress_percentage,
                    ],
                    "/patient/goals/{$goal->id}"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformGoal($goal->fresh(['kine' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'avatar_url');
                }])),
                'message' => 'Goal progress updated'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get goal statistics
     */
    public function statistics()
    {
        $patient = Auth::user();

        $totalGoals = PatientGoal::where('patient_id', $patient->id)->count();
        $completedGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->count();
        $inProgressGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'in_progress')
            ->count();
        $failedGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'failed')
            ->count();

        $averageProgress = PatientGoal::where('patient_id', $patient->id)
            ->avg('progress_percentage') ?? 0;

        $goalsByType = PatientGoal::where('patient_id', $patient->id)
            ->select('metric_type', DB::raw('COUNT(*) as count'), DB::raw('AVG(progress_percentage) as avg_progress'))
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
                'completion_rate' => $totalGoals > 0 ? round(($completedGoals / $totalGoals) * 100, 1) : 0,
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

    /**
     * Helper method to create goal notifications
     */
    private function createGoalNotification($userId, $type, $title, $message, $priority, $metadata = null, $actionUrl = null)
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
