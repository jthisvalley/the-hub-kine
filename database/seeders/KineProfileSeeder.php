<?php
// database/seeders/KineProfileSeeder.php

namespace Database\Seeders;

use App\Models\KineProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class KineProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kines = User::where('role', 'kine')->get();

        $specialties = [
            'Kinésithérapie du sport',
            'Kinésithérapie respiratoire',
            'Kinésithérapie neurologique',
            'Kinésithérapie pédiatrique',
            'Rééducation post-opératoire',
            'Lombalgie et cervicalgie',
            'Rééducation périnéale',
        ];

        $cities = ['Paris', 'Lyon', 'Marseille', 'Toulouse', 'Nice', 'Nantes', 'Strasbourg'];

        foreach ($kines as $kine) {
            KineProfile::create([
                'user_id' => $kine->id,
                'specialty' => $specialties[array_rand($specialties)],
                'bio' => $this->generateBio($kine),
                'address' => $this->generateAddress(),
                'city' => $cities[array_rand($cities)],
                'postal_code' => $this->generatePostalCode(),
                'siret' => $this->generateSiret(),
                'approved' => $kine->is_active, // Only active kines are approved
            ]);
        }

        $this->command->info('✅ Kine profiles seeded successfully!');
    }

    private function generateBio(User $kine): string
    {
        $bios = [
            "Diplômé de l'Institut de Formation en Masso-Kinésithérapie, je me suis spécialisé dans la rééducation sportive. Passionné par mon métier, j'accompagne mes patients vers une récupération optimale.",
            "Avec 10 ans d'expérience en cabinet libéral, je prends en charge les pathologies du rachis et les troubles musculo-squelettiques. Approche globale et personnalisée.",
            "Spécialiste en kinésithérapie respiratoire et neurologique. Je travaille en étroite collaboration avec les médecins pour un suivi optimal des patients.",
            "Jeune kinésithérapeute dynamique, formé aux dernières techniques de rééducation. Mon objectif : vous rendre autonome dans votre récupération.",
            "Ancien kiné du sport en club professionnel, je mets mon expérience au service des sportifs amateurs et professionnels.",
        ];

        return $bios[array_rand($bios)];
    }

    private function generateAddress(): string
    {
        $streets = ['Rue de la République', 'Avenue des Champs-Élysées', 'Boulevard Saint-Germain', 'Rue du Commerce', 'Avenue Jean Jaurès'];
        $numbers = rand(1, 150);

        return $numbers . ' ' . $streets[array_rand($streets)];
    }

    private function generatePostalCode(): string
    {
        return sprintf('%05d', rand(1000, 98800));
    }

    private function generateSiret(): string
    {
        $siret = '';
        for ($i = 0; $i < 14; $i++) {
            $siret .= rand(0, 9);
        }
        return $siret;
    }
}

