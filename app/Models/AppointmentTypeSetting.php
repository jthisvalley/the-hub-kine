<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentTypeSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'type',
        'default_duration',
        'default_price',
        'is_active',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }
}
