<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointsTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'points_transactions';

    protected $fillable = [
        'patient_id',
        'points',
        'type',
        'source',
        'description',
    ];

    protected $casts = [
        'points' => 'integer',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
