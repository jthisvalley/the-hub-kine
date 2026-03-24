<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSettings extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'email_notifications',
        'push_notifications',
        'sms_notifications',
        'share_with_therapist',
        'share_for_research',
        'show_in_directory',
        'font_size',
        'high_contrast',
        'reduce_motion',
        'dark_mode',
        'data_retention_days',
        'auto_delete_old_data',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'push_notifications' => 'boolean',
        'sms_notifications' => 'boolean',
        'share_with_therapist' => 'boolean',
        'share_for_research' => 'boolean',
        'show_in_directory' => 'boolean',
        'font_size' => 'integer',
        'high_contrast' => 'boolean',
        'reduce_motion' => 'boolean',
        'data_retention_days' => 'integer',
        'auto_delete_old_data' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
