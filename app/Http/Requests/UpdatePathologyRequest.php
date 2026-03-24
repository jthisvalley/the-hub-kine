<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePathologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['kine', 'admin']);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('pathologies')->ignore($this->route('id')),
            ],
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:7|regex:/^#[a-fA-F0-9]{6}$/',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'boolean',
            'order_index' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Cette pathologie existe déjà',
            'color.regex' => 'La couleur doit être au format hexadécimal (ex: #3b82f6)',
        ];
    }
}
