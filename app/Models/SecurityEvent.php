<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'security_events';

    protected $fillable = [
        'user_id',
        'event_type',
        'description',
        'ip_address',
        'user_agent',
        'location',
        'device_id',
        'metadata',
        'status',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(UserDevice::class);
    }

    // Event types constants
    const TYPE_LOGIN_SUCCESS = 'login_success';
    const TYPE_LOGIN_FAILED = 'login_failed';
    const TYPE_LOGOUT = 'logout';
    const TYPE_PASSWORD_CHANGE = 'password_change';
    const TYPE_PASSWORD_RESET = 'password_reset';
    const TYPE_EMAIL_CHANGE = 'email_change';
    const TYPE_EMAIL_VERIFIED = 'email_verified';
    const TYPE_DEVICE_ADDED = 'device_added';
    const TYPE_DEVICE_REMOVED = 'device_removed';
    const TYPE_DEVICE_TRUSTED = 'device_trusted';
    const TYPE_DEVICE_UNTRUSTED = 'device_untrusted';
    const TYPE_PROFILE_UPDATE = 'profile_update';
    const TYPE_TWO_FACTOR_ENABLED = 'two_factor_enabled';
    const TYPE_TWO_FACTOR_DISABLED = 'two_factor_disabled';
    const TYPE_SETTINGS_CHANGE = 'settings_change';
}
