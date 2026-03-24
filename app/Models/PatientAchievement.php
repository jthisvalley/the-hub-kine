<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PatientAchievement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'achievement_id',
        'unlocked',
        'unlocked_at',
        'progress',
    ];

    protected $casts = [
        'unlocked' => 'boolean',
        'progress' => 'integer',
        'unlocked_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }
}
