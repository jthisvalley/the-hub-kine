<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'name',
        'description',
        'difficulty',
        'duration',
        'image_url',
        'image_alt',
        'is_template',
        'is_active',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function exercises()
    {
        return $this->hasMany(Exercise::class);
    }

    public function assignments()
    {
        return $this->hasMany(PatientProgramAssignment::class);
    }

    public function getImageUrlAttribute($value)
    {
        if ($value) {
            return asset('public/storage/' . $value);
        }

        return asset('/public/images/program-placeholder.png');
    }
}
