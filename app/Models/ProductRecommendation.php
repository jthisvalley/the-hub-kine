<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ProductRecommendation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'patient_id',
        'product_id',
        'notes',
        'priority',
        'status',
        'assigned_date',
        'purchased_date',
        'usage_start_date',
        'usage_end_date',
        'adherence_notes',
    ];

    protected $casts = [
        'priority' => 'string',
        'status' => 'string',
        'assigned_date' => 'date',
        'purchased_date' => 'datetime',
        'usage_start_date' => 'date',
        'usage_end_date' => 'date',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PURCHASED = 'purchased';
    const STATUS_USING = 'using';
    const STATUS_COMPLETED = 'completed';

    const PRIORITY_HIGH = 'high';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_LOW = 'low';

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
