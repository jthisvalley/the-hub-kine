<?php
// database/seeders/PatientProfileSeeder.php

namespace Database\Seeders;

use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class PatientProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $patients = User::where('role', 'patient')->get();

        $genders = ['male', 'female', 'other'];
        $languages = ['fr', 'en', 'es', 'de'];

        foreach ($patients as $patient) {
            $age = rand(18, 85);
            $birthDate = now()->subYears($age)->subDays(rand(0, 365));

            PatientProfile::create([
                'user_id' => $patient->id,
                'birth_date' => $birthDate,
                'gender' => $genders[array_rand($genders)],
                'height_cm' => rand(150, 200),
                'weight_kg' => rand(50, 120) + (rand(0, 99) / 100),
                'medical_notes' => $this->generateMedicalNotes(),
                'preferred_language' => $languages[array_rand($languages)],
            ]);
        }

        $this->command->info('✅ Patient profiles seeded successfully!');
    }

    private function generateMedicalNotes(): ?string
    {
        $hasNotes = rand(0, 1);

        if (!$hasNotes) {
            return null;
        }

        $conditions = [
            "Antécédent de lombalgie chronique",
            "Opération du ligament croisé antérieur en 2020",
            "Tendinopathie de la coiffe des rotateurs",
            "Sciatalgie droite récidivante",
            "Arthrose du genou grade 2",
            "Scoliose lombaire légère",
            "Épicondylite latérale",
            "Syndrome du canal carpien",
            "Entorse de la cheville récente",
            "Fracture du radius consolidée",
        ];

        $allergies = [
            "Aucune allergie connue",
            "Allergie aux AINS",
            "Allergie au latex",
            "Allergie aux pénicillines",
        ];

        $medications = [
            "Aucun traitement en cours",
            "Paracétamol si nécessaire",
            "Anti-inflammatoire occasionnel",
            "Traitement pour hypertension",
        ];

        $notes = [];

        if (rand(0, 1)) {
            $notes[] = "Condition: " . $conditions[array_rand($conditions)];
        }

        if (rand(0, 1)) {
            $notes[] = "Allergies: " . $allergies[array_rand($allergies)];
        }

        if (rand(0, 1)) {
            $notes[] = "Médications: " . $medications[array_rand($medications)];
        }

        return empty($notes) ? null : implode("\n", $notes);
    }
}
