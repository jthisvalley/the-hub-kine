<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'program_id',
        'category_id',
        'name',
        'description',
        'video_url',
        'duration_seconds',
        'sets',
        'reps',
        'rest_seconds',
        'order_index',
        'difficulty',
        'muscle_groups',
        'instructions',
        'is_active',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'sets' => 'integer',
        'reps' => 'integer',
        'rest_seconds' => 'integer',
        'order_index' => 'integer',
        'instructions' => 'array',
        'muscle_groups' => 'array',
        'is_active' => 'boolean',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function category()
    {
        return $this->belongsTo(ExerciseCategory::class);
    }

    public function sessions()
    {
        return $this->hasMany(ExerciseSession::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeWithCategoryName($query)
    {
        return $query->leftJoin('exercise_categories', 'exercises.category_id', '=', 'exercise_categories.id')
                    ->select('exercises.*', 'exercise_categories.name as category_name');
    }

    public function getCategoryNameAttribute()
    {
        return $this->category ? $this->category->name : 'Uncategorized';
    }
}
