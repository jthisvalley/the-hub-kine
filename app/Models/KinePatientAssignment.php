<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KinePatientAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'patient_id',
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
