<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExerciseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'video_url' => $this->video_url,
            'duration_seconds' => $this->duration_seconds,
            'sets' => $this->sets,
            'reps' => $this->reps,
            'rest_seconds' => $this->rest_seconds,
            'order_index' => $this->order_index,
            'difficulty' => $this->difficulty,
            'muscle_groups' => $this->muscle_groups ?? [],
            'instructions' => $this->instructions ?? [],
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'color' => $this->category->color,
                    'icon' => $this->category->icon,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
