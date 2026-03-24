<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CancellationReason extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'reason',
        'type',
        'is_active',
        'order_index',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_index' => 'integer',
    ];

    public function appointmentReasons()
    {
        return $this->hasMany(AppointmentCancellationReason::class);
    }

    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_cancellation_reasons')
                    ->withPivot('additional_notes')
                    ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order_index')->orderBy('reason');
    }
}
