<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientProgressReport extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'kine_id',
        'title',
        'summary',
        'report_date',
        'report_type',
        'status',
        'pain_level_start',
        'pain_level_current',
        'pain_improvement',
        'mobility_score_start',
        'mobility_score_current',
        'mobility_improvement',
        'adherence_rate',
        'strength_improvement',
        'flexibility_improvement',
        'total_sessions',
        'completed_sessions',
        'missed_sessions',
        'average_session_duration',
        'kine_observations',
        'kine_recommendations',
        'next_steps',
        'patient_comments',
        'patient_satisfaction',
        'attachments',
    ];

    protected $casts = [
        'report_date' => 'date',
        'attachments' => 'array',
        'pain_level_start' => 'decimal:2',
        'pain_level_current' => 'decimal:2',
        'pain_improvement' => 'decimal:2',
        'mobility_score_start' => 'decimal:2',
        'mobility_score_current' => 'decimal:2',
        'mobility_improvement' => 'decimal:2',
        'adherence_rate' => 'decimal:2',
        'strength_improvement' => 'decimal:2',
        'flexibility_improvement' => 'decimal:2',
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

    public function requests()
    {
        return $this->hasMany(ProgressReportRequest::class, 'progress_report_id');
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeForPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    public function scopeForKine($query, $kineId)
    {
        return $query->where('kine_id', $kineId);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('report_date', '>=', now()->subDays($days));
    }

    // Accessors
    public function getCompletionRateAttribute()
    {
        if ($this->total_sessions > 0) {
            return ($this->completed_sessions / $this->total_sessions) * 100;
        }
        return 0;
    }

    public function getFormattedReportDateAttribute()
    {
        return $this->report_date->format('d/m/Y');
    }

    public function getAttachmentUrlsAttribute()
    {
        if (empty($this->attachments)) {
            return [];
        }

        return array_map(function ($attachment) {
            return [
                'name' => $attachment['name'] ?? 'Document',
                'url' => $attachment['url'] ?? '#',
                'type' => $attachment['type'] ?? 'file',
                'size' => $attachment['size'] ?? null,
            ];
        }, $this->attachments);
    }
}
