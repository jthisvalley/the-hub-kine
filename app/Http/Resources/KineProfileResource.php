<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KineProfileResource extends JsonResource
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
            'kine_id' => $this->kine_id,
            'specialization' => $this->specialization,
            'license_number' => $this->license_number,
            'years_of_experience' => $this->years_of_experience,
            'bio' => $this->bio,
            'education' => $this->education,
            'certifications' => $this->certifications,
            'languages' => $this->languages,
            'consultation_fee' => $this->consultation_fee,
            'follow_up_fee' => $this->follow_up_fee,
            'emergency_fee' => $this->emergency_fee,
            'office_address' => $this->office_address,
            'office_city' => $this->office_city,
            'office_postal_code' => $this->office_postal_code,
            'office_country' => $this->office_country,
            'office_phone' => $this->office_phone,
            'availability_hours' => $this->availability_hours,
            'is_accepting_new_patients' => $this->is_accepting_new_patients,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
