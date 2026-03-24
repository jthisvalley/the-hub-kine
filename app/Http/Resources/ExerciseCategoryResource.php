<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExerciseCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_active' => $this->is_active,
            'order_index' => $this->order_index,
            'created_by' => $this->created_by,

            'exercises_count' => $this->whenCounted('exercises'),
            'created_at' => $this->when($request->has('include_timestamps'),
                fn() => $this->created_at?->toISOString()
            ),
            'updated_at' => $this->when($request->has('include_timestamps'),
                fn() => $this->updated_at?->toISOString()
            ),
            'deleted_at' => $this->when($request->has('include_timestamps') && $this->deleted_at,
                fn() => $this->deleted_at?->toISOString()
            ),

            'exercises' => $this->whenLoaded('exercises', function () {
                return ExerciseResource::collection($this->exercises);
            }),

            'creator' => $this->whenLoaded('creator', function () {
                return new UserResource($this->creator);
            }),
        ];
    }
}
