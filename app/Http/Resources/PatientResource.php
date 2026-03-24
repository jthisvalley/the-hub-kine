<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class PatientResource extends JsonResource
{
    public function toArray($request)
    {
        $avatarUrl = $this->avatar_url;
        if (!$avatarUrl) {
            $initial = strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1));
            $avatarUrl = "https://ui-avatars.com/api/?name={$initial}&background=random&color=fff&size=128";
        }

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->first_name . ' ' . $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth,
            'age' => $this->date_of_birth ? Carbon::parse($this->date_of_birth)->age : null,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'avatar_url' => $avatarUrl,
            'pathology' => $this->whenLoaded('patientProfile', function () {
                return $this->patientProfile->pathology ?? 'Non spécifié';
            }),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Marketplace specific fields
            'assignedProducts' => $this->whenLoaded('productRecommendations', function () {
                if (!$this->relationLoaded('productRecommendations') || $this->productRecommendations->isEmpty()) {
                    return [];
                }

                return $this->productRecommendations->map(function ($recommendation) {
                    return $recommendation->product;
                })->filter()->values();
            }) ?? [],

            'status' => $this->is_active ? 'active' : 'inactive',

            'adherenceRate' => $this->when($request->include === 'statistics', function () {
                if (!$this->relationLoaded('exerciseSessions')) {
                    return 0;
                }

                $totalSessions = $this->exerciseSessions->count();
                $completedSessions = $this->exerciseSessions->where('status', 'completed')->count();

                return $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100, 0) : 0;
            }),

            // Last session from completed appointments
            'lastSession' => $this->when($this->relationLoaded('appointmentsWithSlot') && $this->appointmentsWithSlot->isNotEmpty(), function () {
                $lastAppointment = $this->appointmentsWithSlot->first();
                return $lastAppointment && $lastAppointment->slot
                    ? $lastAppointment->slot->start_time->format('Y-m-d')
                    : null;
            }),

            // Next session from upcoming appointments
            'nextSession' => $this->when($this->relationLoaded('appointments') && $this->appointments->isNotEmpty(), function () {
                $nextAppointment = $this->appointments->first();
                return $nextAppointment && $nextAppointment->slot
                    ? $nextAppointment->slot->start_time->format('Y-m-d')
                    : null;
            }),

            'profile' => $this->whenLoaded('patientProfile', function () {
                $notificationPreferences = $this->patientProfile->notification_preferences;

                if (is_array($notificationPreferences)) {
                    $preferences = $notificationPreferences;
                }
                
                elseif (is_string($notificationPreferences)) {
                    $preferences = json_decode($notificationPreferences, true) ?? [
                        'email' => true,
                        'push' => true,
                        'sms' => false
                    ];
                }

                else {
                    $preferences = [
                        'email' => true,
                        'push' => true,
                        'sms' => false
                    ];
                }

                return [
                    'gender' => $this->patientProfile->gender,
                    'height_cm' => (int) $this->patientProfile->height_cm,
                    'weight_kg' => (float) $this->patientProfile->weight_kg,
                    'medical_notes' => $this->patientProfile->medical_notes,
                    'emergency_contact_name' => $this->patientProfile->emergency_contact_name,
                    'emergency_contact_phone' => $this->patientProfile->emergency_contact_phone,
                    'preferred_contact_method' => $this->patientProfile->preferred_contact_method,
                    "preferred_language" => $this->patientProfile->preferred_language,
                    "email_notifications" => $preferences['email'],
                    "push_notifications" => $preferences['push'],
                    "sms_notifications" => $preferences['sms'],
                ];
            }),

            'loyalty_points' => $this->whenLoaded('loyaltyPoints', function () {
                return $this->loyaltyPoints ? [
                    'total_points' => (int) $this->loyaltyPoints->total_points,
                    'available_points' => (int) $this->loyaltyPoints->available_points,
                    'level' => (int) $this->loyaltyPoints->level,
                ] : null;
            }),

            'documents' => $this->whenLoaded('patientDocuments', function () {
                return $this->patientDocuments->map(function ($document) {
                    return [
                        'id' => $document->id,
                        'title' => $document->title,
                        'type' => $document->type,
                        'file_path' => $document->file_path,
                        'file_name' => $document->file_name,
                        'file_size' => $document->file_size,
                        'file_type' => $document->file_type,
                        'notes' => $document->notes,
                        'created_at' => $document->created_at,
                        'uploaded_by' => $document->uploaded_by,
                    ];
                });
            }),

            'recent_activity' => $this->when($this->relationLoaded('exerciseSessions'), function () {
                return $this->exerciseSessions
                    ->sortByDesc('created_at')
                    ->take(5)
                    ->map(function ($session) {
                        return [
                            'id' => $session->id,
                            'type' => 'exercise',
                            'description' => 'Exercice: ' . ($session->exercise->name ?? 'Séance'),
                            'date' => $session->created_at->format('Y-m-d H:i:s'),
                            'status' => $session->status,
                            'pain_level' => $session->pain_level,
                            'duration' => $session->duration_minutes,
                        ];
                    });
            }),

            'pathologies' => PathologyResource::collection(
                $this->whenLoaded('patientProfile.pathologies', function () {
                    return $this->patientProfile->pathologies;
                })
            ),

            'statistics' => $this->when($this->relationLoaded('exerciseSessions'), function () {
                $totalExercises = \App\Models\ExerciseSession::where('patient_id', $this->id)->count();
                $completedExercises = \App\Models\ExerciseSession::where('patient_id', $this->id)
                    ->where('status', 'completed')
                    ->count();

                $totalGoals = \App\Models\PatientGoal::where('patient_id', $this->id)->count();
                $achievedGoals = \App\Models\PatientGoal::where('patient_id', $this->id)
                    ->where('status', 'completed')
                    ->count();

                return [
                    'total_exercises' => $totalExercises,
                    'completed_exercises' => $completedExercises,
                    'adherence_rate' => $totalExercises > 0
                        ? round(($completedExercises / $totalExercises) * 100, 2)
                        : 0,
                    'total_goals' => $totalGoals,
                    'achieved_goals' => $achievedGoals,
                ];
            }),

            'goals' => $this->whenLoaded('goals', function () {
                return $this->goals->map(function ($goal) {
                    return [
                        'id' => $goal->id,
                        'title' => $goal->title,
                        'description' => $goal->description,
                        'type' => $this->mapMetricTypeToGoalType($goal->metric_type),
                        'metricType' => $goal->metric_type,
                        'targetValue' => (float) $goal->target_value,
                        'currentValue' => (float) $goal->current_value,
                        'deadline' => $goal->deadline->format('Y-m-d'),
                        'progress' => $goal->progress_percentage,
                        'status' => $goal->status,
                        'unit' => $goal->unit,
                        'createdAt' => $goal->created_at->format('Y-m-d'),
                        'kine' => $goal->kine ? [
                            'name' => $goal->kine->name,
                            'avatar' => $goal->kine->avatar_url,
                        ] : null,
                    ];
                });
            }),
        ];
    }

    private function mapMetricTypeToGoalType($metricType)
    {
        $mapping = [
            'pain_level' => 'pain',
            'mobility_score' => 'mobility',
            'adherence_rate' => 'adherence',
            'strength_score' => 'strength',
            'flexibility_score' => 'flexibility',
            'session_count' => 'exercises',
        ];

        return $mapping[$metricType] ?? 'general';
    }
}

