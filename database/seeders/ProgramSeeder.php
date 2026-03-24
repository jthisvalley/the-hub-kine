<?php
// database/seeders/ProgramSeeder.php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kines = User::where('role', 'kine')->where('is_active', true)->get();

        $programTemplates = [
            [
                'name' => 'Rééducation Lombalgie',
                'description' => 'Programme complet pour la rééducation des lombalgies chroniques',
                'is_template' => true,
            ],
            [
                'name' => 'Renforcement Genou Post-op',
                'description' => 'Programme de renforcement musculaire après opération du genou',
                'is_template' => true,
            ],
            [
                'name' => 'Étirements Épaules',
                'description' => 'Série d\'étirements pour améliorer la mobilité des épaules',
                'is_template' => true,
            ],
            [
                'name' => 'Stabilisation Cheville',
                'description' => 'Exercices de proprioception et stabilisation de la cheville',
                'is_template' => true,
            ],
            [
                'name' => 'Posture Bureau',
                'description' => 'Exercices pour corriger la posture de travail',
                'is_template' => true,
            ],
        ];

        foreach ($kines as $kine) {
            // Create template programs
            foreach ($programTemplates as $template) {
                Program::create([
                    'kine_id' => $kine->id,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'is_template' => $template['is_template'],
                ]);
            }

            // Create custom programs for specific patients
            $patients = $kine->assignedPatients;

            foreach ($patients as $patient) {
                $programName = "Programme personnalisé " . $patient->last_name;
                $programDesc = "Programme adapté spécifiquement pour " . $patient->first_name . " " . $patient->last_name;

                Program::create([
                    'kine_id' => $kine->id,
                    'name' => $programName,
                    'description' => $programDesc,
                    'is_template' => false,
                ]);
            }
        }

        $this->command->info('✅ Programs seeded successfully!');
    }
}
