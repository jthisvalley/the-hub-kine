<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'patient_name' => $this->patient->fullName(),
            'exercise_id' => $this->exercise_id,
            'exercise_name' => $this->exercise->name,
            'program_assignment_id' => $this->program_assignment_id,
            'program_name' => $this->assignment->program->name ?? null,
            'session_date' => $this->session_date->format('Y-m-d'),
            'session_date_formatted' => $this->session_date->format('d/m/Y'),
            'planned_repetitions' => $this->planned_repetitions,
            'actual_repetitions' => $this->actual_repetitions,
            'pain_level' => $this->pain_level,
            'difficulty' => $this->difficulty,
            'difficulty_label' => $this->getDifficultyLabel(),
            'comments' => $this->comments,
            'duration_minutes' => $this->duration_minutes,
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'completed_at_formatted' => $this->completed_at?->format('d/m/Y H:i'),
            'status' => $this->status,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),

            // Calculated fields
            'completion_rate' => $this->planned_repetitions > 0
                ? round(($this->actual_repetitions / $this->planned_repetitions) * 100)
                : 0,

            // Additional metadata
            'is_today' => $this->session_date->isToday(),
            'is_this_week' => $this->session_date->isCurrentWeek(),
            'pain_level_category' => $this->getPainLevelCategory(),
        ];
    }

    private function getDifficultyLabel(): string
    {
        $labels = [
            'easy' => 'Facile',
            'normal' => 'Normal',
            'hard' => 'Difficile',
            'very_hard' => 'Très difficile',
        ];

        return $labels[$this->difficulty] ?? $this->difficulty;
    }

    private function getPainLevelCategory(): string
    {
        if ($this->pain_level <= 3) return 'low';
        if ($this->pain_level <= 6) return 'medium';
        return 'high';
    }
}
