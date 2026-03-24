<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentCancellationReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'cancellation_reason_id',
        'additional_notes',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function reason()
    {
        return $this->belongsTo(CancellationReason::class);
    }
}
