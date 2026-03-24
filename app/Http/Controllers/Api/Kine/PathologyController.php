<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePathologyRequest;
use App\Http\Requests\UpdatePathologyRequest;
use App\Http\Resources\PathologyResource;
use App\Models\Pathology;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PathologyController extends Controller
{
    /**
     * Display a listing of pathologies.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Pathology::query();

        // Filter by active status
        if ($request->has('active_only') && $request->active_only) {
            $query->where('is_active', true);
        }

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Search by name
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        // Include patients count
        if ($request->has('with_counts') && $request->with_counts) {
            $query->withCount('patients');
        }

        // Order by order_index then name
        $query->orderBy('order_index')->orderBy('name');

        // For kine users, only show pathologies they created or are global
        if ($user && $user->role === 'kine') {
            $query->where(function ($q) use ($user) {
                $q->whereNull('created_by')
                  ->orWhere('created_by', $user->id);
            });
        }

        $pathologies = $query->get();

        return PathologyResource::collection($pathologies);
    }

    /**
     * Store a newly created pathology.
     */
    public function store(StorePathologyRequest $request)
    {
        $user = auth()->user();
        DB::beginTransaction();

        try {
            $pathology = Pathology::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'category' => $request->category,
                'color' => $request->color ?? '#3b82f6',
                'icon' => $request->icon ?? '🏥',
                'is_active' => $request->is_active ?? true,
                'order_index' => $request->order_index ?? 0,
                'created_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pathologie créée avec succès',
                'data' => new PathologyResource($pathology),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la pathologie',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified pathology.
     */
    public function show($id)
    {
        $user = auth()->user();

        $pathology = Pathology::withCount('patients')->findOrFail($id);

        // Check if user has access
        if ($user->role === 'kine' && $pathology->created_by && $pathology->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette pathologie',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new PathologyResource($pathology),
        ]);
    }

    /**
     * Update the specified pathology.
     */
    public function update(UpdatePathologyRequest $request, $id)
    {
        $user = auth()->user();

        $pathology = Pathology::findOrFail($id);

        // Check if user has access
        if ($user->role === 'kine' && $pathology->created_by && $pathology->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette pathologie',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $updateData = [
                'name' => $request->name ?? $pathology->name,
                'description' => $request->description ?? $pathology->description,
                'category' => $request->category ?? $pathology->category,
                'color' => $request->color ?? $pathology->color,
                'icon' => $request->icon ?? $pathology->icon,
                'is_active' => $request->is_active ?? $pathology->is_active,
                'order_index' => $request->order_index ?? $pathology->order_index,
            ];

            // Update slug if name changed
            if ($request->has('name') && $request->name !== $pathology->name) {
                $updateData['slug'] = Str::slug($request->name);
            }

            $pathology->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pathologie mise à jour avec succès',
                'data' => new PathologyResource($pathology->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la pathologie',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified pathology.
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $pathology = Pathology::findOrFail($id);

        // Check if user has access
        if ($user->role === 'kine' && $pathology->created_by && $pathology->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette pathologie',
            ], 403);
        }

        // Check if pathology is used by any patients
        if ($pathology->patients()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cette pathologie est associée à des patients et ne peut pas être supprimée',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $pathology->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pathologie supprimée avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la pathologie',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle pathology status.
     */
    public function toggleStatus($id)
    {
        $user = auth()->user();

        $pathology = Pathology::findOrFail($id);

        // Check if user has access
        if ($user->role === 'kine' && $pathology->created_by && $pathology->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette pathologie',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $pathology->update([
                'is_active' => !$pathology->is_active
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $pathology->is_active ? 'Pathologie activée' : 'Pathologie désactivée',
                'data' => [
                    'is_active' => $pathology->is_active
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pathology categories.
     */
    public function categories()
    {
        $categories = [
            ['value' => 'musculoskeletal', 'label' => 'Musculo-squelettique'],
            ['value' => 'neurological', 'label' => 'Neurologique'],
            ['value' => 'cardiovascular', 'label' => 'Cardiovasculaire'],
            ['value' => 'respiratory', 'label' => 'Respiratoire'],
            ['value' => 'post_surgery', 'label' => 'Post-chirurgical'],
            ['value' => 'sports', 'label' => 'Sportive'],
            ['value' => 'geriatric', 'label' => 'Gériatrique'],
            ['value' => 'pediatric', 'label' => 'Pédiatrique'],
            ['value' => 'other', 'label' => 'Autre'],
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Bulk update order indices.
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:pathologies,id',
            'items.*.order_index' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();

        try {
            foreach ($request->items as $item) {
                Pathology::where('id', $item['id'])->update([
                    'order_index' => $item['order_index']
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ordre mis à jour avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'ordre',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
