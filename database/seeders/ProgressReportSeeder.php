<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PatientProgressReport;
use App\Models\User;

class ProgressReportSeeder extends Seeder
{
    public function run()
    {
        $patients = User::where('role', 'patient')->get();

        foreach ($patients as $patient) {
            PatientProgressReport::factory()
                ->count(rand(2, 5))
                ->forPatient($patient->id)
                ->create();
        }
    }
}
