<?php
// app/Models/Quote.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'content',
        'author',
        'author_title',
        'category',
        'is_active',
        'order_index',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_index' => 'integer',
    ];

    public function kines()
    {
        return $this->belongsToMany(User::class, 'kine_quotes', 'quote_id', 'kine_id')
            ->withPivot('is_active', 'order_index')
            ->withTimestamps();
    }

    public function patients()
    {
        return $this->belongsToMany(
            User::class,
            'kine_quotes',
            'quote_id',
            'patient_id'
        )->withPivot(['kine_id', 'is_active', 'order_index'])
        ->withTimestamps();
    }


    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order_index');
    }
}
