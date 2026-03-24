<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_available' => $this->is_available,
            'slot_date' => $this->slot_date,
            'formatted_start_time' => $this->start_time->format('H:i'),
            'formatted_end_time' => $this->end_time->format('H:i'),
            'duration_minutes' => $this->start_time->diffInMinutes($this->end_time),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
