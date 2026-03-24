<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isKine();
    }

    public function rules(): array
    {
        return [
            'program_id' => 'required|exists:programs,id',
            'patient_ids' => 'required|array',
            'patient_ids.*' => 'exists:users,id',
            'started_at' => 'nullable|date',
            'estimated_end_at' => 'nullable|date|after:started_at',
        ];
    }
}
