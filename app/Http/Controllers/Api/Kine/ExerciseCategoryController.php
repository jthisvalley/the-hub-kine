<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExerciseCategoryRequest;
use App\Http\Requests\UpdateExerciseCategoryRequest;
use App\Http\Resources\ExerciseCategoryResource;
use App\Models\ExerciseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseCategoryController extends Controller
{
    /**
     * Get all exercise categories for the authenticated kine
     */
    public function index(): JsonResponse
    {
        $categories = ExerciseCategory::forKine(auth()->id())
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => ExerciseCategoryResource::collection($categories),
        ]);
    }

    /**
     * Store a newly created exercise category
     */
    public function store(StoreExerciseCategoryRequest $request): JsonResponse
    {
        $category = ExerciseCategory::create([
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color,
            'icon' => $request->icon,
            'is_active' => $request->is_active ?? true,
            'order_index' => $request->order_index ?? 0,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => new ExerciseCategoryResource($category),
        ], 201);
    }

    /**
     * Update the specified exercise category
     */
    public function update(UpdateExerciseCategoryRequest $request, ExerciseCategory $category): JsonResponse
    {
        if ($category->created_by && $category->created_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this category',
            ], 403);
        }

        $category->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => new ExerciseCategoryResource($category),
        ]);
    }

    /**
     * Remove the specified exercise category
     */
    public function destroy(ExerciseCategory $category): JsonResponse
    {
        if ($category->created_by && $category->created_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this category',
            ], 403);
        }

        if ($category->exercises()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category that is in use by exercises',
            ], 400);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Toggle category active status
     */
    public function toggleStatus(ExerciseCategory $category): JsonResponse
    {
        if ($category->created_by && $category->created_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this category',
            ], 403);
        }

        $category->update([
            'is_active' => !$category->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category status updated',
            'data' => new ExerciseCategoryResource($category),
        ]);
    }
}
