<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SessionCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isPatient() || $this->user()->isKine();
    }

    public function rules(): array
    {
        return [
            'exercise_id' => 'required|exists:exercises,id',
            'program_assignment_id' => 'required|exists:patient_program_assignments,id',
            'session_date' => 'required|date',
            'planned_repetitions' => 'required|integer|min:1',
            'actual_repetitions' => 'required|integer|min:0',
            'pain_level' => 'required|integer|min:1|max:10',
            'difficulty' => 'required|in:easy,normal,hard,very_hard',
            'comments' => 'nullable|string',
            'duration_minutes' => 'required|integer|min:1',
            'completed_at' => 'nullable|date',
            'status' => 'required|in:pending,completed,skipped',
        ];
    }
}
