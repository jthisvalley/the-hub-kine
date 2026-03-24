<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentReminder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'appointment_id',
        'reminder_type',
        'reminder_hours_before',
        'sent_at',
        'status',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'reminder_hours_before' => 'integer',
        'created_at' => 'datetime',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
