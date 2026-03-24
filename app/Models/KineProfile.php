<?php
// app/Models/KineProfile.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KineProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'specialty',
        'bio',
        'address',
        'city',
        'postal_code',
        'siret',
        'approved',
        'notification_preferences',
        'adeli_number',
        'specialties',
        'years_of_experience',
        'working_days',
        'working_hours',
        'session_durations',
        'buffer_minutes',
        'emergency_phone',
        'is_emergency_contact_available',
    ];

    protected $casts = [
        'approved' => 'boolean',
        'years_of_experience' => 'integer',
        'buffer_minutes' => 'integer',
        'is_emergency_contact_available' => 'boolean',
        'notification_preferences' => 'array',
        'working_days' => 'array',
        'working_hours' => 'array',
        'session_durations' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patientAssignments()
    {
        return $this->hasMany(KinePatientAssignment::class, 'kine_id', 'user_id');
    }

    public function getEmergencyPhoneAttribute($value)
    {
        return $value ?? $this->user->phone;
    }
}
