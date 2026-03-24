<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PatientPathology extends Pivot
{
    use HasFactory, HasUuids;

    protected $table = 'patient_pathologies';

    protected $fillable = [
        'patient_profile_id',
        'pathology_id',
        'diagnosed_date',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'diagnosed_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function patientProfile()
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function pathology()
    {
        return $this->belongsTo(Pathology::class);
    }
}
