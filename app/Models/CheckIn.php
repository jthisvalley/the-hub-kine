<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckIn extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'exercise_session_id',
        'completed_at',
        'pain_level',
        'notes',
        'duration_seconds',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'pain_level' => 'integer',
        'duration_seconds' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function exerciseSession()
    {
        return $this->belongsTo(ExerciseSession::class, 'exercise_session_id');
    }

    public function exercise()
    {
        return $this->hasOneThrough(
            Exercise::class,
            ExerciseSession::class,
            'id',
            'id',
            'exercise_session_id',
            'exercise_id' 
        );
    }
}
