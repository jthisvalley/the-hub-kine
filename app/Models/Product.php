<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kine_id',
        'name',
        'slug',
        'description',
        'full_description',
        'category_id',
        'subcategory_id',
        'price',
        'original_price',
        'discount',
        'rating',
        'review_count',
        'image_url',
        'gallery_urls',
        'availability',
        'stock_quantity',
        'is_new',
        'is_featured',
        'is_bestseller',
        'rental_price',
        'rental_period',
        'specifications',
        'benefits',
        'how_to_use',
        'is_active',
        'kine_recommendations_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount' => 'integer',
        'rating' => 'decimal:2',
        'review_count' => 'integer',
        'is_new' => 'boolean',
        'is_featured' => 'boolean',
        'is_bestseller' => 'boolean',
        'is_active' => 'boolean',
        'rental_price' => 'decimal:2',
        'gallery_urls' => 'array',
        'specifications' => 'array',
        'benefits' => 'array',
        'how_to_use' => 'array',
        'stock_quantity' => 'integer',
        'kine_recommendations_count' => 'integer',
    ];

    public function kine()
    {
        return $this->belongsTo(User::class, 'kine_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function recommendations()
    {
        return $this->hasMany(ProductRecommendation::class);
    }

    // Add scope for kine's products
    public function scopeByKine($query, $kineId)
    {
        return $query->where('kine_id', $kineId);
    }

    // Scope for global products (no kine_id)
    public function scopeGlobal($query)
    {
        return $query->whereNull('kine_id');
    }

    // Scope for both kine-specific and global products
    public function scopeForKine($query, $kineId)
    {
        return $query->where(function ($q) use ($kineId) {
            $q->where('kine_id', $kineId)
              ->orWhereNull('kine_id');
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeNew($query)
    {
        return $query->where('is_new', true);
    }

    public function scopeBestseller($query)
    {
        return $query->where('is_bestseller', true);
    }
}
