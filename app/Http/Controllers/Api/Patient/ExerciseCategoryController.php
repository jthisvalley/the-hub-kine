<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\ExerciseCategory;
use Illuminate\Http\Request;

class ExerciseCategoryController extends Controller
{
    /**
     * Get exercise categories
     */
    public function index(Request $request)
    {
        $query = ExerciseCategory::active()
            ->ordered();

        // Filter by kine if needed
        if ($request->has('kine_id')) {
            $query->forKine($request->kine_id);
        }

        // Paginate results
        $perPage = $request->per_page ?? 10;
        $categories = $query->paginate($perPage);

        // Transform data
        $data = $categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'color' => $category->color,
                'icon' => $category->icon,
                'is_active' => $category->is_active,
                'order_index' => $category->order_index,
                'created_by' => $category->created_by,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at,
                'exercise_count' => $category->exercises()->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $categories->currentPage(),
                'from' => $categories->firstItem(),
                'last_page' => $categories->lastPage(),
                'links' => $categories->linkCollection()->toArray(),
                'path' => $categories->path(),
                'per_page' => $categories->perPage(),
                'to' => $categories->lastItem(),
                'total' => $categories->total(),
            ],
            'links' => [
                'first' => $categories->url(1),
                'last' => $categories->url($categories->lastPage()),
                'prev' => $categories->previousPageUrl(),
                'next' => $categories->nextPageUrl(),
            ],
        ]);
    }
}
