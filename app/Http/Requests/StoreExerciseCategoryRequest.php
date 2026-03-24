<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExerciseCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isKine();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:exercise_categories,name,NULL,id,created_by,' . auth()->id(),
            'description' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-F]{6}$/i',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
            'order_index' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'description' => 'description',
            'color' => 'couleur',
            'icon' => 'icône',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la catégorie est requis.',
            'name.unique' => 'Vous avez déjà une catégorie avec ce nom.',
            'color.regex' => 'La couleur doit être au format hexadécimal (#RRGGBB).',
        ];
    }
}
