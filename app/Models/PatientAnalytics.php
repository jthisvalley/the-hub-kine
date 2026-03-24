<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientAnalytics extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'patient_analytics';

    protected $fillable = [
        'patient_id',
        'period',
        'total_exercises',
        'completed_exercises',
        'adherence_rate',
        'average_pain_level',
        'average_mobility_level',
        'streak_current',
        'total_points',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'total_exercises' => 'integer',
        'completed_exercises' => 'integer',
        'adherence_rate' => 'decimal:2',
        'average_pain_level' => 'decimal:1',
        'average_mobility_level' => 'decimal:1',
        'streak_current' => 'integer',
        'total_points' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
