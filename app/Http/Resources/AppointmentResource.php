<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'slot' => new AppointmentSlotResource($this->whenLoaded('slot')),
            'reminders' => AppointmentReminderResource::collection($this->whenLoaded('reminders')),
            'status' => $this->status,
            'type' => $this->type,
            'notes' => $this->notes,
            'location' => $this->location,
            'is_online' => $this->is_online,
            'video_link' => $this->video_link,
            'meeting_code' => $this->meeting_code,
            'price' => $this->price,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'color' => $this->getTypeColor($this->type),
            'start_time' => $this->whenLoaded('slot', function () {
                return $this->slot->start_time->toISOString();
            }),
            'end_time' => $this->whenLoaded('slot', function () {
                return $this->slot->end_time->toISOString();
            }),
            'duration_minutes' => $this->whenLoaded('slot', function () {
                return $this->slot->start_time->diffInMinutes($this->slot->end_time);
            }),
            'formatted_date' => $this->whenLoaded('slot', function () {
                return $this->slot->start_time->format('d/m/Y');
            }),
            'formatted_time' => $this->whenLoaded('slot', function () {
                return $this->slot->start_time->format('H:i') . ' - ' . $this->slot->end_time->format('H:i');
            }),
            'report' => $this->whenLoaded('report', function() {
                return [
                    'id' => $this->report->id,
                    'notes' => $this->report->notes,
                    'documents' => $this->report->documents->map(function($doc) {
                        return [
                            'id' => $doc->id,
                            'filename' => $doc->filename,
                            'file_path' => $doc->file_path,
                            'file_size' => $doc->file_size,
                            'mime_type' => $doc->mime_type,
                            'created_at' => $doc->created_at,
                        ];
                    }),
                    'created_at' => $this->report->created_at,
                ];
            }),
        ];
    }

    private function getTypeColor(string $type): string
    {
        return match($type) {
            'consultation' => 'blue',
            'follow_up' => 'green',
            'emergency' => 'red',
            'initial_evaluation' => 'purple',
            'rehabilitation' => 'yellow',
            default => 'gray',
        };
    }
}
