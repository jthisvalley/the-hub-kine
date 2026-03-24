<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PatientProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'birth_date',
        'gender',
        'height_cm',
        'weight_kg',
        'medical_notes',
        'preferred_language',
        'notification_preferences',
        'emergency_contact_name',
        'emergency_contact_phone',
        'preferred_contact_method',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'height_cm' => 'integer',
        'weight_kg' => 'decimal:2',
        'notification_preferences' => 'array',
    ];

    protected $attributes = [
        'notification_preferences' => '{"email": true, "push": true, "sms": false}',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pathologies(): BelongsToMany
    {
        return $this->belongsToMany(Pathology::class, 'patient_pathologies')
            ->withPivot('diagnosed_date', 'notes', 'is_active')
            ->withTimestamps();
    }
}
