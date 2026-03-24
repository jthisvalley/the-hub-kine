<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DailyCheckin extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'checkin_date',
        'overall_pain_level',
        'mood',
        'energy_level',
        'sleep_hours',
        'notes',
    ];

    protected $casts = [
        'checkin_date' => 'date',
        'sleep_hours' => 'decimal:1',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
