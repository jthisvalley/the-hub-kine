<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'plan',
        'price',
        'billing_period',
        'status' => SubscriptionStatus::class,
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'trial_end',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cancel_at_period_end' => 'boolean',
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'trial_end' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
