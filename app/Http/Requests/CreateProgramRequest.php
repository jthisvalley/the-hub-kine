<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isKine();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'difficulty' => 'required|in:beginner,intermediate,advanced,normal,hard,very_hard',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'image_alt' => 'nullable|string|max:255',
            'exercises' => 'required|array|min:1',
            'exercises.*.name' => 'required|string|max:255',
            'exercises.*.description' => 'required|string',
            'exercises.*.video_url' => 'nullable|url',
            'exercises.*.duration_seconds' => 'required|integer|min:1',
            'exercises.*.sets' => 'required|integer|min:1',
            'exercises.*.reps' => 'required|integer|min:1',
            'exercises.*.rest_seconds' => 'required|integer|min:0',
            'exercises.*.order_index' => 'required|integer|min:0',
            'exercises.*.difficulty' => 'required|in:beginner,intermediate,advanced,normal,hard,very_hard',
            'exercises.*.instructions' => 'nullable|array',
            'exercises.*.instructions.*' => 'string',
            'exercises.*.muscle_groups' => 'nullable|array',
            'exercises.*.muscle_groups.*' => 'string|in:quadriceps,ischios,fessiers,mollets,abdominaux,lombaires,dorsaux,pectoraux,deltoides,biceps,triceps,avant_bras,trapèzes,adducteurs,abducteurs',
            'exercises.*.category_id' => 'nullable|exists:exercise_categories,id',
        ];
    }

    /**
     * Handle the FormData parsing manually
     */
    protected function prepareForValidation()
    {
        $data = $this->all();

        // Debug the incoming data
        \Log::info('PrepareForValidation - Raw data keys:', array_keys($data));
        \Log::info('PrepareForValidation - Difficulty:', [$this->get('difficulty')]);
        \Log::info('PrepareForValidation - Exercises:', [$this->get('exercises')]);

        // Parse exercises if it's a JSON string
        $exercises = $this->get('exercises');

        if (is_string($exercises)) {
            $decoded = json_decode($exercises, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Convert to proper array structure
                $parsedExercises = [];

                foreach ($decoded as $index => $exercise) {
                    // Convert object to array if needed
                    if (is_object($exercise)) {
                        $exercise = (array) $exercise;
                    }

                    // Ensure all fields are properly typed
                    $parsedExercises[$index] = [
                        'name' => $exercise['name'] ?? '',
                        'description' => $exercise['description'] ?? '',
                        'video_url' => $exercise['video_url'] ?? null,
                        'duration_seconds' => (int) ($exercise['duration_seconds'] ?? 0),
                        'sets' => (int) ($exercise['sets'] ?? 0),
                        'reps' => (int) ($exercise['reps'] ?? 0),
                        'rest_seconds' => (int) ($exercise['rest_seconds'] ?? 0),
                        'order_index' => (int) ($exercise['order_index'] ?? $index),
                        'difficulty' => $exercise['difficulty'] ?? 'intermediate',
                        'instructions' => is_array($exercise['instructions'] ?? []) ? $exercise['instructions'] : [],
                        'muscle_groups' => is_array($exercise['muscle_groups'] ?? []) ? $exercise['muscle_groups'] : [],
                        'category_id' => $exercise['category_id'] ?? null,
                    ];
                }

                $this->merge(['exercises' => $parsedExercises]);
            }
        }

        // Also parse FormData array notation as fallback
        $exercisesArray = [];
        foreach ($data as $key => $value) {
            if (preg_match('/^exercises\[(\d+)\]\[(.+)\]$/', $key, $matches)) {
                $index = $matches[1];
                $field = $matches[2];

                if (!isset($exercisesArray[$index])) {
                    $exercisesArray[$index] = [];
                }

                $exercisesArray[$index][$field] = $value;
            }
        }

        if (!empty($exercisesArray)) {
            ksort($exercisesArray);
            $this->merge(['exercises' => array_values($exercisesArray)]);
        }

        // Debug the final data
        \Log::info('PrepareForValidation - Final exercises:', $this->get('exercises', []));
    }
}
