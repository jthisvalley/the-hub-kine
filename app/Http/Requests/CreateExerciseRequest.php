<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isKine();
    }

    public function rules(): array
    {
        return [
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'video_url' => 'nullable|url',
            'duration_seconds' => 'required|integer|min:1',
            'sets' => 'required|integer|min:1',
            'reps' => 'required|integer|min:1',
            'rest_seconds' => 'required|integer|min:0',
            'order_index' => 'integer|min:0',
            'difficulty' => 'required|in:beginner,intermediate,advanced',
            'muscle_groups' => 'nullable|array',
            'muscle_groups.*' => 'string',
            'instructions' => 'nullable|array',
            'instructions.*' => 'string',
        ];
    }
}