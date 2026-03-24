<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Product;
use Illuminate\Support\Str;

class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        // Create main categories
        $categories = [
            [
                'name' => 'Tapis et supports',
                'description' => 'Tapis de yoga, tapis de sol, coussins de méditation',
                'icon' => '🛏️',
                'order' => 1,
                'subcategories' => [
                    ['name' => 'Tapis de yoga', 'order' => 1],
                    ['name' => 'Tapis de rééducation', 'order' => 2],
                    ['name' => 'Coussins de méditation', 'order' => 3],
                    ['name' => 'Rouleaux de massage', 'order' => 4],
                ]
            ],
            [
                'name' => 'Bandes élastiques',
                'description' => 'Bandes de résistance, élastiques thérapeutiques',
                'icon' => '🎗️',
                'order' => 2,
                'subcategories' => [
                    ['name' => 'Mini bandes', 'order' => 1],
                    ['name' => 'Bandes longues', 'order' => 2],
                    ['name' => 'Kits de résistance', 'order' => 3],
                    ['name' => 'Bandes avec poignées', 'order' => 4],
                ]
            ],
            [
                'name' => 'Électrostimulateurs',
                'description' => 'Appareils d\'électrothérapie et TENS',
                'icon' => '⚡',
                'order' => 3,
                'subcategories' => [
                    ['name' => 'Électrostimulateurs TENS', 'order' => 1],
                    ['name' => 'Électrostimulateurs EMS', 'order' => 2],
                    ['name' => 'Kits professionnels', 'order' => 3],
                    ['name' => 'Électrodes', 'order' => 4],
                ]
            ],
            [
                'name' => 'Orthèses',
                'description' => 'Supports articulaires et orthèses',
                'icon' => '🦵',
                'order' => 4,
                'subcategories' => [
                    ['name' => 'Orthèses de genou', 'order' => 1],
                    ['name' => 'Orthèses de cheville', 'order' => 2],
                    ['name' => 'Orthèses de poignet', 'order' => 3],
                    ['name' => 'Orthèses lombaires', 'order' => 4],
                ]
            ],
            [
                'name' => 'Aide à la mobilité',
                'description' => 'Canes, déambulateurs, béquilles',
                'icon' => '🦯',
                'order' => 5,
                'subcategories' => [
                    ['name' => 'Canes et cannes anglaises', 'order' => 1],
                    ['name' => 'Béquilles', 'order' => 2],
                    ['name' => 'Déambulateurs', 'order' => 3],
                    ['name' => 'Rollators', 'order' => 4],
                ]
            ],
        ];

        foreach ($categories as $categoryData) {
            $subcategories = $categoryData['subcategories'];
            unset($categoryData['subcategories']);

            $category = Category::create([
                ...$categoryData,
                'slug' => Str::slug($categoryData['name']),
            ]);

            foreach ($subcategories as $subcatData) {
                Subcategory::create([
                    ...$subcatData,
                    'slug' => Str::slug($subcatData['name']),
                    'category_id' => $category->id,
                ]);
            }
        }

        $products = [
            [
                'name' => 'Tapis de Yoga Premium TPE',
                'description' => 'Tapis antidérapant haute densité pour exercices de rééducation',
                'price' => 89.99,
                'original_price' => 119.99,
                'discount' => 25,
                'rating' => 4.8,
                'review_count' => 124,
                'image_url' => 'https://images.unsplash.com/photo-1567281105305-11c3e4ace86b',
                'availability' => 'in-stock',
                'stock_quantity' => 50,
                'is_new' => true,
                'is_featured' => true,
                'category_id' => Category::where('name', 'Tapis et supports')->first()->id,
                'subcategory_id' => Subcategory::where('name', 'Tapis de yoga')->first()->id,
                'specifications' => json_encode([
                    'material' => 'TPE écologique',
                    'dimensions' => '183 x 61 x 0.6 cm',
                    'poids' => '2.5 kg',
                    'couleurs_disponibles' => ['Violet', 'Bleu', 'Vert', 'Gris'],
                ]),
            ],
        ];

        foreach ($products as $productData) {
            Product::create([
                ...$productData,
                'slug' => Str::slug($productData['name']),
            ]);
        }
    }
}
