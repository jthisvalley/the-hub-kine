<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StreakTracker extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'type',
        'current_streak',
        'longest_streak',
        'last_activity_date',
        'start_date',
        'milestones',
        'is_active',
    ];

    protected $casts = [
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'last_activity_date' => 'date',
        'start_date' => 'date',
        'milestones' => 'array',
        'is_active' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function scopeDaily($query)
    {
        return $query->where('type', 'daily');
    }

    public function scopeWeekly($query)
    {
        return $query->where('type', 'weekly');
    }

    public function scopeExercise($query)
    {
        return $query->where('type', 'exercise');
    }
}
