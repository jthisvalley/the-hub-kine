<?php

namespace App\Traits;

use App\Models\{
    Achievement,
    LoyaltyPoints,
    ExerciseSession,
    Milestone,
    PatientAchievement
};

trait HasRewards
{
    public function loyaltyPoints()
    {
        return $this->hasOne(LoyaltyPoints::class, 'patient_id');
    }

    public function exerciseSessions()
    {
        return $this->hasMany(ExerciseSession::class, 'patient_id');
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class, 'patient_id');
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'patient_achievements')
                    ->withPivot('unlocked', 'unlocked_at', 'progress')
                    ->withTimestamps();
    }

    public function getPointsAttribute()
    {
        return $this->loyaltyPoints ? $this->loyaltyPoints->available_points : 0;
    }

    public function getCurrentStreakAttribute()
    {
        return $this->loyaltyPoints ? $this->loyaltyPoints->streak_current : 0;
    }

    public function getLevelAttribute()
    {
        return $this->loyaltyPoints ? $this->loyaltyPoints->level : 1;
    }
}
