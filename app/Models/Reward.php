<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Reward extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'description',
        'points_cost',
        'category',
        'type',
        'stock',
        'available',
        'image_url',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'stock' => 'integer',
        'available' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function redemptions()
    {
        return $this->hasMany(RedeemedReward::class);
    }
}
