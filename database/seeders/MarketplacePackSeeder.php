<?php

namespace Database\Seeders;

use App\Models\MarketplacePack;
use Illuminate\Database\Seeder;

class MarketplacePackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $packs = [
            [
                'name' => 'Pack Débutant',
                'description' => 'Exercices de base pour commencer la rééducation à domicile',
                'price' => 29.99,
                'currency' => 'EUR',
                'is_active' => true,
            ],
            [
                'name' => 'Pack Sportif',
                'description' => 'Programme spécial pour sportifs amateurs et professionnels',
                'price' => 49.99,
                'currency' => 'EUR',
                'is_active' => true,
            ],
            [
                'name' => 'Pack Postural',
                'description' => 'Exercices pour améliorer la posture et prévenir les douleurs',
                'price' => 39.99,
                'currency' => 'EUR',
                'is_active' => true,
            ],
            [
                'name' => 'Pack Sénior',
                'description' => 'Exercices adaptés pour les personnes âgées',
                'price' => 34.99,
                'currency' => 'EUR',
                'is_active' => true,
            ],
            [
                'name' => 'Pack Entreprise',
                'description' => 'Programme de prévention des TMS en entreprise',
                'price' => 199.99,
                'currency' => 'EUR',
                'is_active' => true,
            ],
            [
                'name' => 'Pack Inactif (Test)',
                'description' => 'Ce pack est inactif pour tester l\'affichage',
                'price' => 99.99,
                'currency' => 'EUR',
                'is_active' => false,
            ],
        ];

        foreach ($packs as $pack) {
            MarketplacePack::create($pack);
        }

        $this->command->info('✅ Marketplace packs seeded successfully!');
    }
}
