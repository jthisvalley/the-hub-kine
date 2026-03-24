<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientProgramAssignment;
use App\Models\ExerciseSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PatientProgramController extends Controller
{
    /**
     * Get patient's assigned programs
     */
    public function index(Request $request)
    {
        $patient = Auth::user();

        $query = PatientProgramAssignment::with([
            'program',
            'program.exercises.category',
            'assignedBy'
        ])->where('patient_id', $patient->id);

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->per_page ?? 10;
        $assignments = $query->paginate($perPage);

        $data = $assignments->map(function ($assignment) use ($patient) {
            $totalExercises = $assignment->program->exercises->count();

            $completedSessions = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $assignment->id)
                ->where('status', 'completed')
                ->count();

            $progress = $totalExercises > 0
                ? ($completedSessions / $totalExercises) * 100
                : 0;

            $todayCompleted = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $assignment->id)
                ->where('status', 'completed')
                ->whereDate('session_date', now()->format('Y-m-d'))
                ->count();

            return [
                'id' => $assignment->id,
                'program_id' => $assignment->program_id,
                'name' => $assignment->program->name ?? 'N/A',
                'description' => $assignment->program->description ?? '',
                'difficulty' => $assignment->program->difficulty ?? 'N/A',
                'duration' => $assignment->program->duration ?? 'N/A',
                'total_exercises' => $totalExercises,
                'completed_exercises' => $completedSessions,
                'progress' => round($progress, 2),
                'today_completed' => $todayCompleted,
                'status' => $assignment->status,
                'started_at' => $assignment->started_at?->format('Y-m-d'),
                'estimated_end_at' => $assignment->estimated_end_at?->format('Y-m-d'),
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
                'program' => $assignment->program ? [
                    'id' => $assignment->program->id,
                    'name' => $assignment->program->name,
                    'description' => $assignment->program->description,
                    'difficulty' => $assignment->program->difficulty,
                    'duration' => $assignment->program->duration,
                    'image_url' => $assignment->program->image_url,
                    'image_alt' => $assignment->program->image_alt,
                    'exercises' => $assignment->program->exercises->map(function ($exercise) {
                        return [
                            'id' => $exercise->id,
                            'name' => $exercise->name,
                            'description' => $exercise->description,
                            'video_url' => $exercise->video_url,
                            'duration_seconds' => $exercise->duration_seconds,
                            'sets' => $exercise->sets,
                            'reps' => $exercise->reps,
                            'rest_seconds' => $exercise->rest_seconds,
                            'order_index' => $exercise->order_index,
                            'difficulty' => $exercise->difficulty,
                            'muscle_groups' => $exercise->muscle_groups,
                            'instructions' => $exercise->instructions,
                            'is_active' => $exercise->is_active,
                            'category' => $exercise->category ? [
                                'id' => $exercise->category->id,
                                'name' => $exercise->category->name,
                            ] : null,
                        ];
                    }),
                ] : null,
                'assignedBy' => $assignment->assignedBy ? [
                    'name' => $assignment->assignedBy->name,
                    'specialty' => $assignment->assignedBy->specialty,
                    'avatar' => $assignment->assignedBy->avatar,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'from' => $assignments->firstItem(),
                'last_page' => $assignments->lastPage(),
                'links' => $assignments->linkCollection()->toArray(),
                'path' => $assignments->path(),
                'per_page' => $assignments->perPage(),
                'to' => $assignments->lastItem(),
                'total' => $assignments->total(),
            ],
            'links' => [
                'first' => $assignments->url(1),
                'last' => $assignments->url($assignments->lastPage()),
                'prev' => $assignments->previousPageUrl(),
                'next' => $assignments->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Get program details with exercises
     */
    public function show($programAssignmentId)
    {
        $patient = Auth::user();

        $assignment = PatientProgramAssignment::with([
            'program',
            'program.exercises' => function ($query) {
                $query->active()->orderBy('order_index');
            },
            'program.exercises.category',
            'sessions' => function ($query) {
                $query->orderBy('session_date', 'desc');
            }
        ])->where('patient_id', $patient->id)
          ->where('id', $programAssignmentId)
          ->firstOrFail();

        // Calculate progress
        $totalExercises = $assignment->program->exercises->count();
        $completedSessions = $assignment->sessions->where('status', 'completed')->count();
        $progress = $totalExercises > 0 ? ($completedSessions / $totalExercises) * 100 : 0;

        // Get today's sessions
        $todaySessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('program_assignment_id', $assignment->id)
            ->whereDate('session_date', today())
            ->with('exercise')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'assignment' => [
                    'id' => $assignment->id,
                    'status' => $assignment->status,
                    'started_at' => $assignment->started_at,
                    'estimated_end_at' => $assignment->estimated_end_at,
                    'progress' => round($progress, 2),
                ],
                'program' => [
                    'id' => $assignment->program->id,
                    'name' => $assignment->program->name,
                    'description' => $assignment->program->description,
                    'difficulty' => $assignment->program->difficulty,
                    'duration' => $assignment->program->duration,
                    'image_url' => $assignment->program->image_url,
                    'image_alt' => $assignment->program->image_alt,
                ],
                'exercises' => $assignment->program->exercises->map(function ($exercise) use ($patient, $assignment) {
                    // Get today's session for this exercise
                    $todaySession = ExerciseSession::where('patient_id', $patient->id)
                        ->where('program_assignment_id', $assignment->id)
                        ->where('exercise_id', $exercise->id)
                        ->whereDate('session_date', today())
                        ->first();

                    // Get all sessions for this exercise
                    $allSessions = ExerciseSession::where('patient_id', $patient->id)
                        ->where('program_assignment_id', $assignment->id)
                        ->where('exercise_id', $exercise->id)
                        ->get();

                    $completedCount = $allSessions->where('status', 'completed')->count();
                    $completionRate = $allSessions->count() > 0
                        ? ($completedCount / $allSessions->count()) * 100
                        : 0;
                    return [
                        'id' => $exercise->id,
                        'name' => $exercise->name,
                        'description' => $exercise->description,
                        'video_url' => $exercise->video_url,
                        'duration_seconds' => $exercise->duration_seconds,
                        'sets' => $exercise->sets,
                        'reps' => $exercise->reps,
                        'rest_seconds' => $exercise->rest_seconds,
                        'order_index' => $exercise->order_index,
                        'difficulty' => $exercise->difficulty,
                        'muscle_groups' => $exercise->muscle_groups,
                        'instructions' => $exercise->instructions,
                        'is_active' => $exercise->is_active,
                        'category' => $exercise->category ? [
                            'id' => $exercise->category->id,
                            'name' => $exercise->category->name,
                            'color' => $exercise->category->color,
                            'icon' => $exercise->category->icon,
                        ] : null,
                        'stats' => [
                            'completed_sessions' => $completedCount,
                            'total_sessions' => $allSessions->count(),
                            'completion_rate' => round($completionRate, 2),
                            'last_session' => $allSessions->sortByDesc('session_date')->first(),
                            'today_session' => $todaySession,
                        ],
                    ];
                }),
                'progress' => [
                    'total_exercises' => $totalExercises,
                    'completed_exercises' => $completedSessions,
                    'progress' => round($progress, 2),
                ],
                'today_sessions' => $todaySessions,
            ],
        ]);
    }
}
