<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentSlot extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'start_time',
        'end_time',
        'is_available',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_available' => 'boolean',
        'slot_date' => 'date',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'slot_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true)
            ->where('start_time', '>', now())
            ->doesntHave('appointment');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now());
    }
}
