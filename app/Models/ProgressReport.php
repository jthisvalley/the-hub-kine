<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgressReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'kine_id',
        'title',
        'report_date',
        'summary',
        'pain_improvement',
        'mobility_improvement',
        'adherence_percentage',
        'kine_notes',
        'recommendations',
    ];

    protected $casts = [
        'report_date' => 'date',
        'pain_improvement' => 'decimal:2',
        'mobility_improvement' => 'decimal:2',
        'adherence_percentage' => 'decimal:2',
        'created_at' => 'datetime',
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
