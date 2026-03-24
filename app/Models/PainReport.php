<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PainReport extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'kine_id',
        'pain_level',
        'location',
        'description',
        'trigger',
        'duration',
        'medications',
        'relieving_factors',
        'worsening_factors',
        'affects_sleep',
        'affects_daily_activities',
        'status',
        'kine_notes',
        'reviewed_at',
        'reviewed_by',
        'metadata',
    ];

    protected $casts = [
        'pain_level' => 'integer',
        'affects_sleep' => 'boolean',
        'affects_daily_activities' => 'boolean',
        'medications' => 'array',
        'relieving_factors' => 'array',
        'worsening_factors' => 'array',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the patient who reported the pain
     */
    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * Get the kine assigned to this pain report
     */
    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    /**
     * Get the kine who reviewed this report
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope a query to only include reports for a specific patient
     */
    public function scopeForPatient($query, $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    /**
     * Scope a query to only include reports for a specific kine
     */
    public function scopeForKine($query, $kineId)
    {
        return $query->where('kine_id', $kineId);
    }

    /**
     * Scope a query to only include unreviewed reports
     */
    public function scopeUnreviewed($query)
    {
        return $query->whereNull('reviewed_at');
    }

    /**
     * Scope a query to only include reports with a specific status
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include reports from the last X days
     */
    public function scopeLastDays($query, $days)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Mark the report as reviewed
     */
    public function markAsReviewed($kineId, $notes = null)
    {
        $this->status = 'reviewed';
        $this->reviewed_at = now();
        $this->reviewed_by = $kineId;

        if ($notes) {
            $this->kine_notes = $notes;
        }

        $this->save();

        return $this;
    }

    /**
     * Mark the report as addressed (action taken)
     */
    public function markAsAddressed($kineId, $notes = null)
    {
        $this->status = 'addressed';
        $this->reviewed_at = now();
        $this->reviewed_by = $kineId;

        if ($notes) {
            $this->kine_notes = $notes;
        }

        $this->save();

        return $this;
    }

    /**
     * Check if the report is urgent (high pain level)
     */
    public function isUrgent()
    {
        return $this->pain_level >= 8;
    }

    /**
     * Get the pain level label
     */
    public function getPainLevelLabelAttribute()
    {
        return match(true) {
            $this->pain_level <= 3 => 'Léger',
            $this->pain_level <= 6 => 'Modéré',
            $this->pain_level <= 8 => 'Intense',
            default => 'Sévère',
        };
    }

    /**
     * Get the location label in French
     */
    public function getLocationLabelAttribute()
    {
        $locations = [
            'shoulder' => 'Épaule',
            'back' => 'Dos',
            'lower_back' => 'Bas du dos',
            'upper_back' => 'Haut du dos',
            'neck' => 'Cou',
            'knee' => 'Genou',
            'hip' => 'Hanche',
            'ankle' => 'Cheville',
            'foot' => 'Pied',
            'wrist' => 'Poignet',
            'hand' => 'Main',
            'elbow' => 'Coude',
            'head' => 'Tête',
            'other' => 'Autre',
        ];

        return $locations[$this->location] ?? $this->location;
    }
}
