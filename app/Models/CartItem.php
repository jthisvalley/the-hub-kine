<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CartItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'product_id',
        'service_pack_id',
        'quantity',
        'added_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'added_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function servicePack()
    {
        return $this->belongsTo(MarketplacePack::class, 'service_pack_id');
    }
}
