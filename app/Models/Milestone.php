<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Milestone extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'title',
        'description',
        'type',
        'achieved',
        'achieved_date',
        'target_value',
        'current_value',
        'icon',
    ];

    protected $casts = [
        'achieved' => 'boolean',
        'target_value' => 'integer',
        'current_value' => 'integer',
        'achieved_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
