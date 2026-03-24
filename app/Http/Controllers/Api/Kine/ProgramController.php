<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignProgramRequest;
use App\Http\Requests\CreateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\PatientProgramAssignment;
use App\Models\Program;
use App\Models\ExerciseSession;
use App\Models\User;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isKine()) {
            $query = Program::withCount(['exercises', 'assignments'])
                ->with(['exercises', 'assignments.patient'])
                ->where('kine_id', $user->id);
        } else {
            $query = Program::withCount(['exercises'])
                ->with(['exercises'])
                ->whereHas('assignments', function ($q) use ($user) {
                    $q->where('patient_id', $user->id)
                      ->where('status', 'active');
                });
        }

        if ($request->has('is_template')) {
            $query->where('is_template', $request->boolean('is_template'));
        }

        $programs = $query->get();

        $programs = $programs->map(function ($program) use ($user) {
            $durationInWeeks = $program->duration ? ceil($program->duration / 7) : 0;
            $program->duration_weeks = $durationInWeeks;

            if ($user->isKine()) {
                $totalSessions = 0;
                $completedSessions = 0;
                $totalPossibleSessions = 0;

                $assignments = $program->assignments;

                foreach ($assignments as $assignment) {
                    $sessions = ExerciseSession::where('program_assignment_id', $assignment->id)->get();
                    $totalSessions += $sessions->count();
                    $completedSessions += $sessions->where('status', 'completed')->count();

                    $exercisesCount = $program->exercises()->count();
                    $totalPossibleSessions += $exercisesCount;
                }

                $program->total_sessions = $totalSessions;
                $program->completed_sessions = $completedSessions;
                $program->progress = $totalPossibleSessions > 0 ? round(($completedSessions / $totalPossibleSessions) * 100) : 0;

                $assignedTo = $assignments->map(function ($assignment) {
                    return [
                        'id' => $assignment->patient->id,
                        'first_name' => $assignment->patient->first_name,
                        'last_name' => $assignment->patient->last_name,
                        'progress' => $this->calculatePatientProgress($assignment),
                        'adherence_rate' => $this->calculateAdherenceRate($assignment),
                        'status' => $assignment->status,
                        'completed_at' => $assignment->completed_at,
                    ];
                });

                $program->assigned_to = $assignedTo;
                $program->assigned_patients_count = $assignments->count();
                $program->active_assignments_count = $assignments->where('status', 'active')->count();
                $program->completed_assignments_count = $assignments->where('status', 'completed')->count();

            } else {
                $assignment = $program->assignments->firstWhere('patient_id', $user->id);
                if ($assignment) {
                    $sessions = ExerciseSession::where('program_assignment_id', $assignment->id)->get();
                    $completedSessions = $sessions->where('status', 'completed')->count();
                    $totalSessions = $sessions->count();

                    $program->completed_exercises = $completedSessions;
                    $program->total_sessions = $totalSessions;
                    $program->progress = $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0;
                }
            }

            return $program;
        });

        return response()->json([
            'data' => ProgramResource::collection($programs),
            'meta' => [
                'total' => $programs->count(),
                'active_programs' => $programs->where('active_assignments_count', '>', 0)->count(),
                'completed_programs' => $programs->where('completed_assignments_count', '>', 0)->count(),
            ],
        ]);
    }

    /**
     * Calculate patient progress for a specific assignment
     */
    private function calculatePatientProgress($assignment): int
    {
        $sessions = ExerciseSession::where('program_assignment_id', $assignment->id)->get();
        $completedSessions = $sessions->where('status', 'completed')->count();

        $program = $assignment->program;
        $totalExercises = $program->exercises()->count();

        return $totalExercises > 0 ? round(($completedSessions / $totalExercises) * 100) : 0;
    }

    /**
     * Calculate patient adherence rate for a specific assignment
     */
    private function calculateAdherenceRate($assignment): int
    {
        $thirtyDaysAgo = now()->subDays(30);

        $sessions = ExerciseSession::where('program_assignment_id', $assignment->id)
            ->where('session_date', '>=', $thirtyDaysAgo)
            ->get();

        $completedSessions = $sessions->where('status', 'completed')->count();
        $totalSessions = $sessions->count();

        return $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0;
    }

    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'difficulty' => 'required|in:beginner,intermediate,advanced,normal,hard,very_hard',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'duration' => 'required|integer',
            'image_alt' => 'nullable|string|max:255',
        ]);

        $exercises = [];
        if ($request->has('exercises') && is_string($request->exercises)) {
            $exercisesData = json_decode($request->exercises, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($exercisesData)) {
                return response()->json([
                    'message' => 'Invalid exercises data',
                ], 422);
            }

            foreach ($exercisesData as $index => $exercise) {
                $validator = Validator::make($exercise, [
                    'name' => 'required|string|max:255',
                    'description' => 'required|string',
                    'video_url' => 'nullable|url',
                    'duration_seconds' => 'required|integer|min:1',
                    'sets' => 'required|integer|min:1',
                    'reps' => 'required|integer|min:1',
                    'rest_seconds' => 'required|integer|min:0',
                    'order_index' => 'required|integer|min:0',
                    'difficulty' => 'required|in:beginner,intermediate,advanced,normal,hard,very_hard',
                    'instructions' => 'nullable|array',
                    'instructions.*' => 'string',
                    'muscle_groups' => 'nullable|array',
                    'muscle_groups.*' => 'string|in:quadriceps,ischios,fessiers,mollets,abdominaux,lombaires,dorsaux,pectoraux,deltoides,biceps,triceps,avant_bras,trapèzes,adducteurs,abducteurs',
                    'category_id' => 'nullable|exists:exercise_categories,id',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Validation failed for exercise ' . ($index + 1),
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $exercises[] = $exercise;
            }
        }

        if (empty($exercises)) {
            return response()->json([
                'message' => 'At least one exercise is required',
            ], 422);
        }

        return DB::transaction(function () use ($request, $exercises) {
            $imagePath = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imagePath = $image->store('program-images', 'public');
            }

            $program = Program::create([
                'kine_id' => $request->user()->id,
                'name' => $request->name,
                'description' => $request->description,
                'difficulty' => $request->difficulty,
                'duration' => $request->duration,
                'image_url' => $imagePath,
                'image_alt' => $request->image_alt,
            ]);

            foreach ($exercises as $exerciseData) {
                $program->exercises()->create([
                    'name' => $exerciseData['name'],
                    'description' => $exerciseData['description'],
                    'video_url' => $exerciseData['video_url'] ?? null,
                    'duration_seconds' => $exerciseData['duration_seconds'],
                    'sets' => $exerciseData['sets'],
                    'reps' => $exerciseData['reps'],
                    'rest_seconds' => $exerciseData['rest_seconds'],
                    'order_index' => $exerciseData['order_index'],
                    'difficulty' => $exerciseData['difficulty'],
                    'instructions' => $exerciseData['instructions'] ?? [],
                    'muscle_groups' => $exerciseData['muscle_groups'] ?? [],
                    'category_id' => $exerciseData['category_id'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Program created successfully',
                'data' => $program->load(['exercises.category', 'kine']),
            ], 201);
        });
    }

    /**
     * Update an existing program
     */
    public function update(Request $request, Program $program): JsonResponse
    {
        $user = $request->user();

        if ($program->kine_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier ce programme.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'difficulty' => 'sometimes|required|in:beginner,intermediate,advanced,normal,hard,very_hard',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'duration' => 'sometimes|required|integer|min:1',
            'image_alt' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request, $program) {
            $updateData = [
                'name' => $request->input('name', $program->name),
                'description' => $request->input('description', $program->description),
                'difficulty' => $request->input('difficulty', $program->difficulty),
                'duration' => $request->input('duration', $program->duration),
                'image_alt' => $request->input('image_alt', $program->image_alt),
            ];

            if ($request->hasFile('image')) {
                if ($program->image_url) {
                    Storage::disk('public')->delete($program->image_url);
                }

                $image = $request->file('image');
                $imagePath = $image->store('program-images', 'public');
                $updateData['image_url'] = $imagePath;
            }

            $program->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Program mis à jour avec succès',
                'data' => new ProgramResource($program->load(['exercises', 'kine'])),
            ]);
        });
    }

    /**
     * Delete a program (soft delete or make inactive)
     */
    public function destroy(Request $request, Program $program): JsonResponse
    {
        $user = $request->user();

        if ($program->kine_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce programme.',
            ], 403);
        }

        $hasActiveAssignments = PatientProgramAssignment::where('program_id', $program->id)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveAssignments) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce programme car il est assigné à des patients actifs.',
            ], 400);
        }

        return DB::transaction(function () use ($program) {
            if ($program->image_url) {
                Storage::disk('public')->delete($program->image_url);
            }

            $program->delete();

            return response()->json([
                'success' => true,
                'message' => 'Programme supprimé avec succès',
            ]);
        });
    }

    /**
     * Toggle program active status
     */
    public function toggleStatus(Request $request, Program $program): JsonResponse
    {
        $user = $request->user();

        if ($program->kine_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier ce programme.',
            ], 403);
        }

        $hasActiveAssignments = PatientProgramAssignment::where('program_id', $program->id)
            ->where('status', 'active')
            ->exists();

        if ($program->is_active && $hasActiveAssignments) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de désactiver ce programme car il est assigné à des patients actifs.',
            ], 400);
        }

        $program->update([
            'is_active' => !$program->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => $program->is_active ? 'Programme activé avec succès' : 'Programme désactivé avec succès',
            'data' => new ProgramResource($program),
        ]);
    }

    public function show(Request $request, Program $program): JsonResponse
    {
        $user = $request->user();

        if ($user->isKine()) {
            if ($program->kine_id !== $user->id) {
                return response()->json([
                    'message' => 'Vous n\'avez pas accès à ce programme.',
                ], 403);
            }
        } else {
            $isAssigned = PatientProgramAssignment::where('patient_id', $user->id)
                ->where('program_id', $program->id)
                ->where('status', 'active')
                ->exists();

            if (!$isAssigned) {
                return response()->json([
                    'message' => 'Ce programme ne vous est pas assigné.',
                ], 403);
            }
        }

        $program->load([
            'exercises' => function ($query) {
                $query->orderBy('order_index');
            },
            'kine' => function ($query) {
                $query->select('id', 'first_name', 'last_name', 'email');
            }
        ]);

        if ($user->isKine()) {
            $program->load([
                'assignments.patient' => function ($query) {
                    $query->select('id', 'first_name', 'last_name', 'email', 'is_active');
                }
            ]);

            $assignedPatients = [];
            $totalCompletedSessions = 0;
            $totalPossibleSessions = 0;

            foreach ($program->assignments as $assignment) {
                $totalSessions = ExerciseSession::where('program_assignment_id', $assignment->id)->count();
                $completedSessions = ExerciseSession::where('program_assignment_id', $assignment->id)
                    ->where('status', 'completed')
                    ->count();

                $exercisesCount = $program->exercises()->count();
                $totalPossibleSessions += $exercisesCount;
                $totalCompletedSessions += $completedSessions;

                $progress = $exercisesCount > 0 ? round(($completedSessions / $exercisesCount) * 100) : 0;

                $assignedPatients[] = [
                    'id' => $assignment->patient->id,
                    'first_name' => $assignment->patient->first_name,
                    'last_name' => $assignment->patient->last_name,
                    'email' => $assignment->patient->email,
                    'is_active' => $assignment->patient->is_active,
                    'assigned_at' => $assignment->started_at,
                    'progress' => $progress,
                    'completed_sessions' => $completedSessions,
                    'total_sessions' => $exercisesCount,
                    'status' => $assignment->status,
                    'completed_at' => $assignment->completed_at,
                ];
            }

            $program->assigned_patients = $assignedPatients;
            $program->assigned_patients_count = count($assignedPatients);
            $program->active_assignments_count = $program->assignments->where('status', 'active')->count();
            $program->completed_assignments_count = $program->assignments->where('status', 'completed')->count();
            $program->progress = $totalPossibleSessions > 0 ? round(($totalCompletedSessions / $totalPossibleSessions) * 100) : 0;
        }

        if ($user->isPatient()) {
            $assignment = $program->assignments()->where('patient_id', $user->id)->first();
            if ($assignment) {
                $completedSessions = $assignment->sessions()->where('status', 'completed')->count();
                $totalExercises = $program->exercises()->count();

                $program->completed_exercises = $completedSessions;
                $program->progress = $totalExercises > 0 ? round(($completedSessions / $totalExercises) * 100) : 0;
            }
        }

        if ($user->isKine()) {
            $assignments = $program->assignments()->withCount([
                'sessions' => function ($query) {
                    $query->where('status', 'completed');
                }
            ])->get();

            $totalSessions = $assignments->sum('sessions_count');
            $totalPossibleSessions = $program->exercises()->count() * $assignments->count();

            $program->completed_exercises = $totalSessions;
            $program->progress = $totalPossibleSessions > 0 ? round(($totalSessions / $totalPossibleSessions) * 100) : 0;
        }

        return response()->json([
            'data' => new ProgramResource($program),
        ]);
    }

    public function assign(AssignProgramRequest $request): JsonResponse
    {
        $kine = $request->user();
        $assignments = [];

        DB::beginTransaction();

        try {
            foreach ($request->patient_ids as $patientId) {
                $assignment = PatientProgramAssignment::updateOrCreate(
                    [
                        'patient_id' => $patientId,
                        'program_id' => $request->program_id,
                    ],
                    [
                        'assigned_by' => $kine->id,
                        'started_at' => $request->started_at ?? now(),
                        'estimated_end_at' => $request->estimated_end_at ?? null,
                        'status' => 'active',
                    ]
                );

                $patient = User::find($patientId);
                if ($patient && !$patient->is_active) {
                    $patient->update(['is_active' => true]);
                }

                $assignments[] = $assignment;

                // Get program details for notification
                $program = Program::find($request->program_id);

                // Send notification to patient
                $this->createProgramNotification(
                    $patientId,
                    'program.assigned',
                    'Nouveau programme assigné',
                    "Vous avez reçu un nouveau programme: {$program->name}",
                    NotificationPriority::MEDIUM,
                    [
                        'program_id' => $program->id,
                        'program_name' => $program->name,
                        'assignment_id' => $assignment->id,
                        'started_at' => $assignment->started_at->toISOString(),
                    ],
                    "/patient/programs/{$program->id}"
                );

                // Send notification to kine (confirmation)
                $this->createProgramNotification(
                    $kine->id,
                    'program.assigned.confirmation',
                    'Programme assigné',
                    "Le programme '{$program->name}' a été assigné à {$patient->first_name} {$patient->last_name}",
                    NotificationPriority::LOW,
                    [
                        'program_id' => $program->id,
                        'program_name' => $program->name,
                        'patient_id' => $patientId,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    ],
                    "/kine/programs/{$program->id}/patients/{$patientId}"
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Program assigned successfully',
                'data' => $assignments,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error assigning program',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function templates(Request $request): JsonResponse
    {
        $templates = Program::withCount(['exercises'])
            ->with(['exercises'])
            ->where('is_template', true)
            ->orWhere(function ($query) use ($request) {
                $query->where('kine_id', $request->user()->id)
                      ->where('is_template', true);
            })
            ->get();

        return response()->json([
            'data' => ProgramResource::collection($templates),
        ]);
    }

    /**
     * Helper method to create program notifications
     */
    private function createProgramNotification($userId, $type, $title, $message, $priority, $metadata = null, $actionUrl = null)
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
