<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isKine();
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'video_url' => 'nullable|url',
            'duration_seconds' => 'sometimes|required|integer|min:1',
            'sets' => 'sometimes|required|integer|min:1',
            'reps' => 'sometimes|required|integer|min:1',
            'rest_seconds' => 'sometimes|required|integer|min:0',
            'difficulty' => 'sometimes|required|in:beginner,intermediate,advanced,normal,hard,very_hard',
            'instructions' => 'nullable|array',
            'instructions.*' => 'string',
            'muscle_groups' => 'nullable|array',
            'muscle_groups.*' => 'string',
            'category_id' => 'nullable|exists:exercise_categories,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'exercice est requis',
            'name.max' => 'Le nom de l\'exercice ne peut pas dépasser 255 caractères',
            'description.required' => 'La description de l\'exercice est requise',
            'video_url.url' => 'L\'URL de la vidéo doit être valide',
            'duration_seconds.required' => 'La durée est requise',
            'duration_seconds.min' => 'La durée doit être d\'au moins 1 seconde',
            'sets.required' => 'Le nombre de séries est requis',
            'sets.min' => 'Le nombre de séries doit être d\'au moins 1',
            'reps.required' => 'Le nombre de répétitions est requis',
            'reps.min' => 'Le nombre de répétitions doit être d\'au moins 1',
            'rest_seconds.required' => 'Le temps de repos est requis',
            'rest_seconds.min' => 'Le temps de repos doit être d\'au moins 0 seconde',
            'difficulty.required' => 'La difficulté est requise',
            'difficulty.in' => 'La difficulté doit être débutant, intermédiaire, avancé, normal, difficile ou très difficile',
            'category_id.exists' => 'La catégorie sélectionnée n\'existe pas',
        ];
    }
}
