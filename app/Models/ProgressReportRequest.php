<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgressReportRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'kine_id',
            'reason',
        'urgency',
        'preferred_date',
        'type',
        'specific_concerns',
        'status',
        'kine_notes',
        'reviewed_at',
        'scheduled_at',
        'completed_at',
        'progress_report_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'reviewed_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function progressReport()
    {
        return $this->belongsTo(PatientProgressReport::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    public function scopeForKine($query, $kineId)
    {
        return $query->where('kine_id', $kineId);
    }

    public function scopeUrgent($query)
    {
        return $query->where('urgency', 'high');
    }

    // Accessors
    public function getIsUrgentAttribute()
    {
        return $this->urgency === 'high';
    }

    public function getIsPendingAttribute()
    {
        return $this->status === 'pending';
    }

    public function getDaysSinceRequestAttribute()
    {
        return $this->created_at->diffInDays(now());
    }
}
