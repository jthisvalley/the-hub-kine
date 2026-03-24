<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PointsActivity extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'exercise_session_id',
        'milestone_id',
        'achievement_id',
        'points',
        'activity_type',
        'description',
        'streak_bonus',
        'daily_bonus',
        'metadata',
    ];

    protected $casts = [
        'points' => 'integer',
        'streak_bonus' => 'integer',
        'daily_bonus' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function exerciseSession()
    {
        return $this->belongsTo(ExerciseSession::class);
    }

    public function milestone()
    {
        return $this->belongsTo(Milestone::class);
    }

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }
}
