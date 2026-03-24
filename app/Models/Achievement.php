<?php

namespace App\Models;

use App\Enums\AchievementTier;
use App\Enums\AchievementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Achievement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'title',
        'description',
        'icon',
        'type' => AchievementType::class,
        'tier' => AchievementTier::class,
        'points',
        'category',
        'target_value',
    ];

    protected $casts = [
        'points' => 'integer',
        'target_value' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function patients()
    {
        return $this->belongsToMany(
            User::class,
            'patient_achievements',
            'achievement_id',
            'patient_id'
        )
        ->withPivot('unlocked', 'unlocked_at', 'progress')
        ->withTimestamps();
    }

}
