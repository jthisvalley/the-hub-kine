<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'slot_id',
        'status',
        'type',
        'notes',
        'location',
        'is_online',
        'video_link',
        'meeting_code',
        'price',
        'color',
        'cancelled_by',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
    'color' => '#3b82f6',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($appointment) {
            if (!$appointment->color) {
                $appointment->color = match($appointment->type) {
                    'consultation' => 'blue',
                    'follow_up' => 'green',
                    'emergency' => 'red',
                    'initial_evaluation' => 'purple',
                    'rehabilitation' => 'yellow',
                    default => 'gray',
                };
            }
        });
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function slot()
    {
        return $this->belongsTo(AppointmentSlot::class);
    }

    public function reminders()
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCanceled($query)
    {
        return $query->where('status', 'canceled');
    }

    public function scopeForKine($query, $kineId)
    {
        return $query->whereHas('slot', function ($query) use ($kineId) {
            $query->where('kine_id', $kineId);
        });
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereHas('slot', function ($query) use ($startDate, $endDate) {
            $query->whereBetween('start_time', [$startDate, $endDate]);
        });
    }

    public function scopeOrderBySlotTime($query, $direction = 'asc')
    {
        return $query->orderBy(
            AppointmentSlot::select('start_time')
                ->whereColumn('appointment_slots.id', 'appointments.slot_id')
                ->limit(1),
            $direction
        );
    }

    public function isUpcoming()
    {
        return $this->status === 'scheduled' && $this->slot->start_time > now();
    }

    public function report()
    {
        return $this->hasOne(AppointmentReport::class);
    }

    public function hasReport()
    {
        return $this->report()->exists();
    }

    public function cancellationReasons()
    {
        return $this->belongsToMany(CancellationReason::class, 'appointment_cancellation_reasons')
                    ->withPivot('additional_notes')
                    ->withTimestamps();
    }

    public function hasCancellationReason()
    {
        return $this->cancellationReasons()->exists();
    }
}
