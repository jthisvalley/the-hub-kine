<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AvailabilitySetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'working_days',
        'work_start',
        'work_end',
        'has_lunch_break',
        'lunch_start',
        'lunch_end',
        'default_duration',
        'buffer_time',
        'max_advance_booking',
        'min_advance_booking',
        'email_reminders',
        'sms_reminders',
        'reminder_time',
    ];

    protected $casts = [
        'working_days' => 'array',
        'has_lunch_break' => 'boolean',
        'email_reminders' => 'boolean',
        'sms_reminders' => 'boolean',
        'work_start' => 'datetime:H:i',
        'work_end' => 'datetime:H:i',
        'lunch_start' => 'datetime:H:i',
        'lunch_end' => 'datetime:H:i',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }
}
