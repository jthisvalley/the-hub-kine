<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExerciseSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'program_assignment_id',
        'exercise_id',
        'session_date',
        'session_time',
        'planned_repetitions',
        'actual_repetitions',
        'pain_level',
        'difficulty',
        'comments',
        'duration_minutes',
        'completed_at',
        'status',
    ];

    protected $casts = [
        'session_date' => 'date',
        'session_time' => 'datetime',
        'planned_repetitions' => 'integer',
        'actual_repetitions' => 'integer',
        'pain_level' => 'integer',
        'duration_minutes' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }

    public function assignment()
    {
        return $this->belongsTo(PatientProgramAssignment::class, 'program_assignment_id');
    }

    public function checkIns()
    {
        return $this->hasMany(CheckIn::class);
    }

    public function getCategoryAttribute()
    {
        return $this->exercise->category ?? null;
    }

    public function getCategoryNameAttribute()
    {
        return $this->exercise->category_name ?? 'Uncategorized';
    }

    public function getCategoryIdAttribute()
    {
        return $this->exercise->category_id ?? null;
    }
}
