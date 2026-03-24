<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ProgressMetric extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'metric_type',
        'value',
        'unit',
        'measured_at',
        'notes',
        'source',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'measured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
