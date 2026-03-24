<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KineQuote extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'kine_quotes';

    protected $fillable = [
        'kine_id',
        'quote_id',
        'is_active',
        'order_index',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_index' => 'integer',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
