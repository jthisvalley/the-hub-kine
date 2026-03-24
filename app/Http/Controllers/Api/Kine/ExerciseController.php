<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExerciseRequest;
use App\Http\Requests\UpdateExerciseRequest;
use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use App\Models\Program;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExerciseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Exercise::query()->with('program.kine');

        if ($request->has('program_id')) {
            $query->where('program_id', $request->program_id);
        }

        if ($request->has('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }

        $exercises = $query->orderBy('order_index')->get();

        return response()->json([
            'data' => ExerciseResource::collection($exercises),
            'meta' => [
                'total' => $exercises->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $program = Program::findOrFail($request->program_id);

        if ($program->kine_id !== auth()->id()) {
            return response()->json([
                'message' => 'Vous n\'avez pas la permission d\'ajouter un exercice à ce programme.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $exercise = Exercise::create([
                'program_id' => $request->program_id,
                'name' => $request->name,
                'description' => $request->description,
                'video_url' => $request->video_url,
                'duration_seconds' => $request->duration_seconds,
                'sets' => $request->sets,
                'reps' => $request->reps,
                'rest_seconds' => $request->rest_seconds,
                'order_index' => $request->order_index ?? 0,
                'difficulty' => $request->difficulty,
                'muscle_groups' => $request->muscle_groups ?? [],
                'instructions' => $request->instructions ?? [],
                'category_id' => $request->category_id,
            ]);

            // Create notification for the patient associated with this program
            if ($program->patient_id) {
                $this->createExerciseNotification(
                    $program->patient_id,
                    'exercise.created',
                    'Nouvel exercice ajouté',
                    "Un nouvel exercice '{$exercise->name}' a été ajouté à votre programme.",
                    NotificationPriority::MEDIUM,
                    [
                        'exercise_id' => $exercise->id,
                        'program_id' => $program->id,
                        'exercise_name' => $exercise->name,
                        'difficulty' => $exercise->difficulty,
                        'duration_seconds' => $exercise->duration_seconds,
                    ],
                    "/patient/programs/{$program->id}/exercises/{$exercise->id}"
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Exercise created successfully',
                'data' => new ExerciseResource($exercise->load('program')),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating exercise: ' . $e->getMessage());

            return response()->json([
                'message' => 'Erreur lors de la création de l\'exercice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Exercise $exercise): JsonResponse
    {
        return response()->json([
            'data' => new ExerciseResource($exercise->load('program')),
        ]);
    }

    public function update(UpdateExerciseRequest $request, Exercise $exercise): JsonResponse
    {
        Log::info($request->all());

        if ($exercise->program->kine_id !== auth()->id()) {
            return response()->json([
                'message' => 'Vous n\'avez pas la permission de modifier cet exercice.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $oldName = $exercise->name;
            $exercise->update($request->validated());

            // Create notification for the patient when exercise is updated
            if ($exercise->program->patient_id) {
                $this->createExerciseNotification(
                    $exercise->program->patient_id,
                    'exercise.updated',
                    'Exercice modifié',
                    "L'exercice '{$oldName}' a été modifié. Nouveau nom: '{$exercise->name}'.",
                    NotificationPriority::LOW,
                    [
                        'exercise_id' => $exercise->id,
                        'program_id' => $exercise->program_id,
                        'exercise_name' => $exercise->name,
                        'old_name' => $oldName,
                        'changes' => $request->validated(),
                    ],
                    "/patient/programs/{$exercise->program_id}/exercises/{$exercise->id}"
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Exercise updated successfully',
                'data' => new ExerciseResource($exercise->fresh()->load('program')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating exercise: ' . $e->getMessage());

            return response()->json([
                'message' => 'Erreur lors de la modification de l\'exercice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Exercise $exercise): JsonResponse
    {
        $user = auth()->user();

        if ($user->isKine()) {
            if ($exercise->program->kine_id !== $user->id) {
                return response()->json([
                    'message' => 'Vous n\'avez pas la permission de supprimer cet exercice.',
                ], 403);
            }
        }

        DB::beginTransaction();

        try {
            $exerciseName = $exercise->name;
            $programId = $exercise->program_id;
            $patientId = $exercise->program->patient_id;

            $exercise->delete();

            // Create notification for the patient when exercise is deleted
            if ($patientId) {
                $this->createExerciseNotification(
                    $patientId,
                    'exercise.deleted',
                    'Exercice supprimé',
                    "L'exercice '{$exerciseName}' a été supprimé de votre programme.",
                    NotificationPriority::MEDIUM,
                    [
                        'exercise_name' => $exerciseName,
                        'program_id' => $programId,
                        'deleted_at' => now()->toISOString(),
                    ],
                    "/patient/programs/{$programId}"
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Exercice supprimé avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting exercise: ' . $e->getMessage());

            return response()->json([
                'message' => 'Erreur lors de la suppression de l\'exercice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'exercises' => 'required|array',
            'exercises.*.id' => 'required|exists:exercises,id',
            'exercises.*.order_index' => 'required|integer',
        ]);

        DB::beginTransaction();

        try {
            $reorderedExercises = [];

            foreach ($request->exercises as $item) {
                $exercise = Exercise::find($item['id']);

                if ($exercise->program->kine_id !== auth()->id()) {
                    return response()->json([
                        'message' => 'Vous n\'avez pas la permission de modifier cet exercice.',
                    ], 403);
                }

                $exercise->update(['order_index' => $item['order_index']]);
                $reorderedExercises[] = [
                    'id' => $exercise->id,
                    'name' => $exercise->name,
                    'order_index' => $item['order_index'],
                ];
            }

            // Optional: Create notification for the patient about reordering
            if (!empty($reorderedExercises) && isset($exercise)) {
                $program = $exercise->program;

                if ($program->patient_id) {
                    $this->createExerciseNotification(
                        $program->patient_id,
                        'exercise.reordered',
                        'Ordre des exercices modifié',
                        "L'ordre des exercices dans votre programme a été réorganisé.",
                        NotificationPriority::LOW,
                        [
                            'program_id' => $program->id,
                            'exercises' => $reorderedExercises,
                        ],
                        "/patient/programs/{$program->id}"
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Exercises reordered successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reordering exercises: ' . $e->getMessage());

            return response()->json([
                'message' => 'Erreur lors de la réorganisation des exercices',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper method to create exercise notifications
     */
    private function createExerciseNotification(
        $userId,
        $type,
        $title,
        $message,
        $priority,
        $metadata = null,
        $actionUrl = null
    ) {
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
