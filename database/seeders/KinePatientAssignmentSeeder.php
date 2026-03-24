<?php
// database/seeders/KinePatientAssignmentSeeder.php

namespace Database\Seeders;

use App\Models\KinePatientAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

class KinePatientAssignmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all active kines
        $kines = User::where('role', 'kine')
            ->where('is_active', true)
            ->where('email', '!=', 'kine.inactive@hub-kine.com')
            ->get();

        // Get all active patients (except the one without kine)
        $patients = User::where('role', 'patient')
            ->where('is_active', true)
            ->where('email', '!=', 'patient.no-kine@hub-kine.com')
            ->get();

        // Assign patients to kines
        $kineIndex = 0;
        $kineCount = count($kines);

        foreach ($patients as $index => $patient) {
            $kine = $kines[$kineIndex];

            KinePatientAssignment::create([
                'kine_id' => $kine->id,
                'patient_id' => $patient->id,
            ]);

            // Move to next kine, cycling through available kines
            $kineIndex = ($kineIndex + 1) % $kineCount;
        }

        // Manually assign specific patients for testing
        $sophie = User::where('email', 'kine1@hub-kine.com')->first();
        $thomas = User::where('email', 'kine2@hub-kine.com')->first();
        $marie = User::where('email', 'kine3@hub-kine.com')->first();

        $patient1 = User::where('email', 'patient1@hub-kine.com')->first();
        $patient2 = User::where('email', 'patient2@hub-kine.com')->first();
        $patient3 = User::where('email', 'patient3@hub-kine.com')->first();
        $patient4 = User::where('email', 'patient4@hub-kine.com')->first();
        $patient5 = User::where('email', 'patient5@hub-kine.com')->first();
        $patient6 = User::where('email', 'patient6@hub-kine.com')->first();

        // Clear previous assignments for specific patients
        KinePatientAssignment::whereIn('patient_id', [
            $patient1->id, $patient2->id, $patient3->id,
            $patient4->id, $patient5->id, $patient6->id
        ])->delete();

        // Assign specific patients for testing scenarios
        // Kine 1 (Sophie) has patients 1, 2, 3
        KinePatientAssignment::create(['kine_id' => $sophie->id, 'patient_id' => $patient1->id]);
        KinePatientAssignment::create(['kine_id' => $sophie->id, 'patient_id' => $patient2->id]);
        KinePatientAssignment::create(['kine_id' => $sophie->id, 'patient_id' => $patient3->id]);

        // Kine 2 (Thomas) has patients 4, 5
        KinePatientAssignment::create(['kine_id' => $thomas->id, 'patient_id' => $patient4->id]);
        KinePatientAssignment::create(['kine_id' => $thomas->id, 'patient_id' => $patient5->id]);

        // Kine 3 (Marie) has patient 6
        KinePatientAssignment::create(['kine_id' => $marie->id, 'patient_id' => $patient6->id]);

        $this->command->info('✅ Kine-Patient assignments seeded successfully!');
        $this->command->info("   Sophie Dubois (kine1) has 3 patients");
        $this->command->info("   Thomas Leroy (kine2) has 2 patients");
        $this->command->info("   Marie Bernard (kine3) has 1 patient");
    }
}
