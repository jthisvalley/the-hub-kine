<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RedeemedReward extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'reward_id',
        'points_spent',
        'status',
        'redeemed_at',
        'delivered_at',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'redeemed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function reward()
    {
        return $this->belongsTo(Reward::class);
    }
}
