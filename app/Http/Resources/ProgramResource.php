<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'image_alt' => $this->image_alt,
            'difficulty' => $this->difficulty,
            'duration' => $this->duration,
            'progress' => $this->progress ?? 0,
            'completed_exercises' => $this->completed_exercises ?? 0,
            'total_exercises' => $this->exercises_count ?? $this->exercises->count(),
            'total_sessions' => $this->total_sessions ?? 0,
            'completed_sessions' => $this->completed_sessions ?? 0,
            'assigned_patients_count' => $this->assigned_patients_count ?? 0,
            'assigned_to' => $this->when($this->assigned_to, $this->assigned_to),
            'exercises' => $this->whenLoaded('exercises', function () {
                return ExerciseResource::collection($this->exercises->load('category'));
            }),
            'created_by' => $this->whenLoaded('kine', fn() => $this->kine->fullName()),
            'kine' => new KineProfileResource($this->whenLoaded('kine')),
            'assigned_patients' => $this->when(isset($this->assigned_patients), $this->assigned_patients),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
