<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'report_id',
        'filename',
        'file_path',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function report()
    {
        return $this->belongsTo(AppointmentReport::class, 'report_id');
    }
}
