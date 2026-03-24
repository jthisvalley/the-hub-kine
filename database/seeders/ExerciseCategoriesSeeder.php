<?php

namespace Database\Seeders;

use App\Models\ExerciseCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExerciseCategoriesSeeder extends Seeder
{
    public function run()
    {
        $kine = User::where('id','3fbe6057-dfe0-45d8-8245-bb982326d1dc')->first();
        $categories = [
            [
                'name' => 'Mobilité',
                'slug' => 'mobilite',
                'description' => 'Exercices pour améliorer l\'amplitude des mouvements',
                'color' => '#3B82F6',
                'icon' => 'activity',
                'created_by' => $kine->id,
                'order_index' => 1,
            ],
            [
                'name' => 'Renforcement',
                'slug' => 'renforcement',
                'description' => 'Exercices de renforcement musculaire',
                'color' => '#10B981',
                'icon' => 'zap',
                'created_by' => $kine->id,
                'order_index' => 2,
            ],
            [
                'name' => 'Étirements',
                'slug' => 'etirements',
                'description' => 'Exercices d\'étirement et de souplesse',
                'color' => '#8B5CF6',
                'icon' => 'wind',
                'created_by' => $kine->id,
                'order_index' => 3,
            ],
            [
                'name' => 'Stabilisation',
                'slug' => 'stabilisation',
                'description' => 'Exercices pour la stabilité et l\'équilibre',
                'color' => '#F59E0B',
                'icon' => 'shield',
                'created_by' => $kine->id,
                'order_index' => 4,
            ],
            [
                'name' => 'Respiratoire',
                'slug' => 'respiratoire',
                'description' => 'Exercices de respiration et rééducation respiratoire',
                'color' => '#EF4444',
                'icon' => 'lungs',
                'created_by' => $kine->id,
                'order_index' => 5,
            ],
            [
                'name' => 'Postural',
                'slug' => 'postural',
                'description' => 'Exercices de correction posturale',
                'color' => '#6366F1',
                'icon' => 'align-center',
                'created_by' => $kine->id,
                'order_index' => 6,
            ],
            [
                'name' => 'Proprioception',
                'slug' => 'proprioception',
                'description' => 'Exercices de conscience corporelle et proprioception',
                'color' => '#EC4899',
                'icon' => 'eye',
                'created_by' => $kine->id,
                'order_index' => 7,
            ],
        ];

        foreach ($categories as $category) {
            ExerciseCategory::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
