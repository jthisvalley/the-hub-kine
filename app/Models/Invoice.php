<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'kine_id',
        'invoice_number',
        'service_type',
        'amount',
        'tax',
        'total_amount',
        'notes',
        'currency',
        'status',
        'paid_at',
        'due_date',
        'pdf_url',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
        'due_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

}
