<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientGoal extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'kine_id',
        'title',
        'description',
        'metric_type',
        'target_value',
        'current_value',
        'unit',
        'deadline',
        'status',
        'progress_percentage',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'progress_percentage' => 'integer',
        'deadline' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }
}
