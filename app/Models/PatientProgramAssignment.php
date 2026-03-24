<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientProgramAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'program_id',
        'assigned_by',
        'started_at',
        'estimated_end_at',
        'completed_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'estimated_end_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function sessions()
    {
        return $this->hasMany(ExerciseSession::class, 'program_assignment_id');
    }
}
