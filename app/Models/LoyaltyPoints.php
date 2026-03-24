<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LoyaltyPoints extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'loyalty_points';

    protected $fillable = [
        'patient_id',
        'total_points',
        'available_points',
        'level',
        'streak_current',
        'streak_longest',
        'last_activity_date',
        'last_exercise_date',
        'exercises_completed_today',
        'daily_streak_bonus_active',
        'weekly_streak_bonus_active',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'available_points' => 'integer',
        'level' => 'integer',
        'streak_current' => 'integer',
        'streak_longest' => 'integer',
        'last_activity_date' => 'date',
        'last_exercise_date' => 'date',
        'exercises_completed_today' => 'integer',
        'daily_streak_bonus_active' => 'boolean',
        'weekly_streak_bonus_active' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function activities()
    {
        return $this->hasMany(PointsActivity::class, 'patient_id', 'patient_id');
    }
}
