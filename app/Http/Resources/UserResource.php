<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'role' => $this->role,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'last_login' => $this->last_login?->toISOString(),

            'patient_profile' => $this->when($this->isPatient() && $this->relationLoaded('patientProfile'), function () {
                return new PatientResource($this->patientProfile);
            }),

            'kine_profile' => $this->when($this->isKine() && $this->relationLoaded('kineProfile'), function () {
                return new KineProfileResource($this->kineProfile);
            }),

            'settings' => $this->whenLoaded('settings', function () {
                return new UserSettingsResource($this->settings);
            }),

            'assigned_kine' => $this->when($this->isPatient() && $this->relationLoaded('assignedKine'), function () {
                return new UserResource($this->assignedKine);
            }),

            'assigned_patients' => $this->when($this->isKine() && $this->relationLoaded('assignedPatients'), function () {
                return UserResource::collection($this->assignedPatients);
            }),

            'programs' => $this->whenLoaded('programs', function () {
                return ProgramResource::collection($this->programs);
            }),

            'assigned_programs' => $this->when($this->isPatient() && $this->relationLoaded('assignedPrograms'), function () {
                return ProgramResource::collection($this->assignedPrograms);
            }),

            'programs_count' => $this->whenCounted('programs'),
            'assigned_patients_count' => $this->whenCounted('assignedPatients'),
            'assigned_programs_count' => $this->whenCounted('assignedPrograms'),
            'appointments_count' => $this->whenCounted('appointments'),
            'checkins_count' => $this->whenCounted('checkIns'),
            'exercise_sessions_count' => $this->whenCounted('exerciseSessions'),

            'created_at' => $this->when($request->has('include_timestamps'),
                fn() => $this->created_at?->toISOString()
            ),
            'updated_at' => $this->when($request->has('include_timestamps'),
                fn() => $this->updated_at?->toISOString()
            ),
            'deleted_at' => $this->when($request->has('include_timestamps') && $this->deleted_at,
                fn() => $this->deleted_at?->toISOString()
            ),
        ];
    }
}
