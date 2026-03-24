<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'device_id',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(UserDevice::class, 'device_id');
    }

    public function tokenable()
    {
        return $this->morphTo('tokenable');
    }
}
