<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MarketplaceController extends Controller
{
    /**
     * Get all categories
     */
    public function getCategories(Request $request)
    {
        try {
            $categories = Category::where('is_active', true)
                ->whereNull('parent_id')
                ->with(['subcategories' => function ($query) {
                    $query->where('is_active', true)->orderBy('order');
                }])
                ->orderBy('order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all products with filters
     */
    public function getProducts(Request $request)
    {
        try {
            $query = Product::active()->with(['category', 'subcategory']);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('full_description', 'like', "%{$search}%");
                });
            }

            // Category filter
            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            }

            // Subcategory filter
            if ($request->has('subcategory_id') && $request->subcategory_id) {
                $query->where('subcategory_id', $request->subcategory_id);
            }

            // Price range filter
            if ($request->has('min_price') && $request->min_price) {
                $query->where('price', '>=', $request->min_price);
            }
            if ($request->has('max_price') && $request->max_price) {
                $query->where('price', '<=', $request->max_price);
            }

            // Availability filter
            if ($request->has('availability')) {
                $query->where('availability', $request->availability);
            }

            // Special filters
            if ($request->boolean('is_new')) {
                $query->where('is_new', true);
            }
            if ($request->boolean('is_featured')) {
                $query->where('is_featured', true);
            }
            if ($request->boolean('is_bestseller')) {
                $query->where('is_bestseller', true);
            }

            // Sort by
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 12);
            $products = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => 'Products fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get kine's recommended products
     */
    public function getKineProducts(Request $request)
    {
        try {
            $kineId = Auth::id();

            $query = Product::ByKine($kineId)
                ->with(['category', 'subcategory'])
                ->where('is_active', true)
                ->withCount(['recommendations as kine_recommendation_count' => function ($q) use ($kineId) {
                    $q->where('kine_id', $kineId);
                }]);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Category filter
            if ($request->has('category_id') && $request->category_id && $request->category_id !== 'all') {
                $query->where('category_id', $request->category_id);
            }

            // Subcategory filter
            if ($request->has('subcategory_id') && $request->subcategory_id) {
                $query->where('subcategory_id', $request->subcategory_id);
            }

            // Price range filter
            if ($request->has('min_price') && $request->min_price) {
                $query->where('price', '>=', $request->min_price);
            }
            if ($request->has('max_price') && $request->max_price) {
                $query->where('price', '<=', $request->max_price);
            }

            // Availability filter
            if ($request->has('availability') && $request->availability !== 'all') {
                $query->where('availability', $request->availability);
            }

            // Special filters
            if ($request->has('is_new') && $request->boolean('is_new')) {
                $query->where('is_new', true);
            }
            if ($request->has('is_featured') && $request->boolean('is_featured')) {
                $query->where('is_featured', true);
            }
            if ($request->has('is_bestseller') && $request->boolean('is_bestseller')) {
                $query->where('is_bestseller', true);
            }

            // Status filter (recommended/not_recommended)
            if ($request->has('status') && $request->status !== 'all') {
                if ($request->status === 'recommended') {
                    $query->whereHas('recommendations', function ($q) use ($kineId) {
                        $q->where('kine_id', $kineId);
                    });
                } elseif ($request->status === 'not_recommended') {
                    $query->whereDoesntHave('recommendations', function ($q) use ($kineId) {
                        $q->where('kine_id', $kineId);
                    });
                }
            }

            // Sort by - FIX: Handle kine_recommendation_count properly
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            // If sorting by recommendation count, we need to sort by the withCount result
            if ($sortBy === 'kine_recommendation_count') {
                $query->orderBy('kine_recommendation_count', $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 12);
            $products = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => 'Products fetched successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch kine products: ' . $e->getMessage(), [
                'request' => $request->all(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get single product details
     */
    public function getProduct($id)
    {
        try {
            $product = Product::with(['category', 'subcategory', 'reviews.patient'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    /**
     * Get kine's product recommendations for patients
     */
    public function getRecommendations(Request $request)
    {
        try {
            $kineId = Auth::id();

            $query = ProductRecommendation::with([
                'product.category',
                'product.subcategory',
                'patient'
            ])->where('kine_id', $kineId);

            // Global search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('product', function ($productQuery) use ($search) {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    })
                    ->orWhereHas('patient', function ($patientQuery) use ($search) {
                        $patientQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhere('notes', 'like', "%{$search}%");
                });
            }

            // Status filter
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Patient filter
            if ($request->has('patient_id') && $request->patient_id !== 'all') {
                $query->where('patient_id', $request->patient_id);
            }

            // Priority filter
            if ($request->has('priority') && $request->priority !== 'all') {
                $query->where('priority', $request->priority);
            }

            // Date range filter
            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('assigned_date', '>=', $request->start_date);
            }
            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('assigned_date', '<=', $request->end_date);
            }

            // Sort by
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            // Handle nested sorting
            if (str_contains($sortBy, '.')) {
                [$relation, $column] = explode('.', $sortBy);
                if ($relation === 'product') {
                    $query->join('products', 'product_recommendations.product_id', '=', 'products.id')
                        ->orderBy("products.{$column}", $sortOrder);
                } elseif ($relation === 'patient') {
                    $query->join('users', 'product_recommendations.patient_id', '=', 'users.id')
                        ->orderBy("users.{$column}", $sortOrder);
                }
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);

            $recommendations = $query->paginate($perPage, ['*'], 'page', $page);

            // Get unique patients for filter
            $uniquePatients = User::where('role', 'patient')
                ->whereHas('productRecommendations', function ($q) use ($kineId) {
                    $q->where('kine_id', $kineId);
                })
                ->select('id', 'first_name', 'last_name', 'email')
                ->get()
                ->map(function ($patient) {
                    return [
                        'id' => $patient->id,
                        'full_name' => $patient->first_name . ' ' . $patient->last_name,
                        'email' => $patient->email,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'recommendations' => $recommendations->items(),
                    'meta' => [
                        'total' => $recommendations->total(),
                        'per_page' => $recommendations->perPage(),
                        'current_page' => $recommendations->currentPage(),
                        'last_page' => $recommendations->lastPage(),
                        'from' => $recommendations->firstItem(),
                        'to' => $recommendations->lastItem(),
                    ],
                    'filters' => [
                        'patients' => $uniquePatients,
                        'statuses' => [
                            ['value' => 'pending', 'label' => 'En attente'],
                            ['value' => 'purchased', 'label' => 'Acheté'],
                            ['value' => 'using', 'label' => 'En utilisation'],
                            ['value' => 'completed', 'label' => 'Terminé'],
                        ],
                        'priorities' => [
                            ['value' => 'high', 'label' => 'Haute'],
                            ['value' => 'medium', 'label' => 'Moyenne'],
                            ['value' => 'low', 'label' => 'Basse'],
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch recommendations: ' . $e->getMessage(), [
                'request' => $request->all(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recommendations',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update recommendation status
     */
    public function updateRecommendationStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:pending,purchased,using,completed',
                'adherence_notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $kineId = Auth::id();

            $recommendation = ProductRecommendation::where('kine_id', $kineId)
                ->findOrFail($id);

            $updateData = ['status' => $request->status];

            if ($request->status === ProductRecommendation::STATUS_PURCHASED) {
                $updateData['purchased_date'] = now();
            } elseif ($request->status === ProductRecommendation::STATUS_USING) {
                $updateData['usage_start_date'] = now();
            } elseif ($request->status === ProductRecommendation::STATUS_COMPLETED) {
                $updateData['usage_end_date'] = now();
            }

            if ($request->adherence_notes) {
                $updateData['adherence_notes'] = $request->adherence_notes;
            }

            $recommendation->update($updateData);

            // Return updated recommendation with relationships
            $recommendation->load(['product.category', 'product.subcategory', 'patient']);

            return response()->json([
                'success' => true,
                'data' => $recommendation,
                'message' => 'Statut mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to update recommendation: ' . $e->getMessage(), [
                'request' => $request->all(),
                'user_id' => Auth::id(),
                'recommendation_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update recommendation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    /**
     * Create product recommendation for a patient
     */
    public function createRecommendation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'patient_id' => 'required|exists:users,id',
                'product_id' => 'required|exists:products,id',
                'notes' => 'nullable|string|max:1000',
                'priority' => 'required|in:high,medium,low',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $recommendation = ProductRecommendation::create([
                'kine_id' => Auth::id(),
                'patient_id' => $request->patient_id,
                'product_id' => $request->product_id,
                'notes' => $request->notes,
                'priority' => $request->priority,
                'status' => ProductRecommendation::STATUS_PENDING,
                'assigned_date' => now(),
            ]);

            // Increment recommendation count on product
            Product::where('id', $request->product_id)
                ->increment('kine_recommendations_count');

            return response()->json([
                'success' => true,
                'data' => $recommendation->load(['product', 'patient']),
                'message' => 'Product recommended successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create recommendation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new product
     */
    public function storeProduct(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'required|string',
                'price' => 'required|numeric|min:0',
                'category_id' => 'nullable|exists:categories,id',
                'image' => 'nullable|image|max:2048',
                'availability' => 'in:in-stock,limited,out-of-stock',
                'stock_quantity' => 'integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $kineId = Auth::id();
            $data = $request->only([
                'name', 'description', 'full_description',
                'category_id', 'subcategory_id', 'price',
                'original_price', 'discount', 'availability',
                'stock_quantity', 'rental_price', 'rental_period'
            ]);

            $data['is_new'] = $request->boolean('is_new') ? 1 : 0;
            $data['is_featured'] = $request->boolean('is_featured') ? 1 : 0;
            $data['is_bestseller'] = $request->boolean('is_bestseller') ? 1 : 0;
            $data['kine_id'] = $kineId;
            $data['is_active'] = 1;

            if (!$request->has('slug') || empty($request->slug)) {
                $data['slug'] = Str::slug($request->name);
            }

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('products', 'public');
                $data['image_url'] = asset('storage/' . $path);
            }

            $product = Product::create($data);

            return response()->json([
                'success' => true,
                'data' => $product->load(['category', 'subcategory']),
                'message' => 'Product created successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new category
     */
    public function storeCategory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:categories,name',
                'description' => 'nullable|string',
                'icon' => 'nullable|string',
                'parent_id' => 'nullable|exists:categories,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category = Category::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'icon' => $request->icon,
                'parent_id' => $request->parent_id,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Category created successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new subcategory
     */
    public function storeSubcategory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'category_id' => 'required|exists:categories,id',
                'icon' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $subcategory = Subcategory::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'category_id' => $request->category_id,
                'icon' => $request->icon,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'data' => $subcategory->load('category'),
                'message' => 'Subcategory created successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subcategory',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete recommendation
     */
    public function deleteRecommendation($id)
    {
        try {
            $recommendation = ProductRecommendation::where('kine_id', Auth::id())
                ->findOrFail($id);

            // Decrement recommendation count
            Product::where('id', $recommendation->product_id)
                ->decrement('kine_recommendations_count');

            $recommendation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Recommendation deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete recommendation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get marketplace statistics
     */
    public function getStatistics()
    {
        try {
            $kineId = Auth::id();

            $totalRecommendations = ProductRecommendation::where('kine_id', $kineId)->count();
            $activeRecommendations = ProductRecommendation::where('kine_id', $kineId)
                ->whereIn('status', ['pending', 'using'])
                ->count();
            $purchasedCount = ProductRecommendation::where('kine_id', $kineId)
                ->whereIn('status', ['purchased', 'using', 'completed'])
                ->count();

            $completedRecommendations = ProductRecommendation::where('kine_id', $kineId)
                ->where('status', 'completed')
                ->count();
            $totalWithStatus = ProductRecommendation::where('kine_id', $kineId)
                ->whereIn('status', ['purchased', 'using', 'completed'])
                ->count();

            $adherenceRate = $totalWithStatus > 0 ?
                round(($completedRecommendations / $totalWithStatus) * 100) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_recommendations' => $totalRecommendations,
                    'active_recommendations' => $activeRecommendations,
                    'purchased_count' => $purchasedCount,
                    'adherence_rate' => $adherenceRate,
                    'unique_patients' => ProductRecommendation::where('kine_id', $kineId)
                        ->distinct('patient_id')
                        ->count('patient_id'),
                    'unique_products' => ProductRecommendation::where('kine_id', $kineId)
                        ->distinct('product_id')
                        ->count('product_id'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get popular products among kines
     */
    public function getPopularProducts()
    {
        try {
            $popularProducts = Product::active()
                ->where('kine_recommendations_count', '>', 0)
                ->orderBy('kine_recommendations_count', 'desc')
                ->take(8)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $popularProducts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch popular products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
