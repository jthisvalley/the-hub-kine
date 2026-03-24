<?php

namespace Database\Seeders;

use App\Models\CheckIn;
use App\Models\ExerciseSession;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CheckInSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding check-ins for analytics...');

        $patients = User::where('role', 'patient')->get();
        $startDate = Carbon::now()->subMonths(3);

        foreach ($patients as $patient) {
            // Get exercise sessions for this patient
            $sessions = ExerciseSession::where('patient_id', $patient->id)
                ->whereNotNull('completed_at')
                ->get();

            foreach ($sessions as $session) {
                // 80% chance to create a check-in for completed sessions
                if (rand(1, 100) <= 80) {
                    CheckIn::create([
                        'id' => Str::uuid(),
                        'patient_id' => $patient->id,
                        'exercise_id' => $session->exercise_id, // Could be null
                        'exercise_session_id' => $session->id,
                        'completed_at' => $session->completed_at,
                        'pain_level' => rand(1, 5),
                        'notes' => $this->getCheckinNotes($session->pain_level ?? 3),
                        'duration_seconds' => $session->duration_seconds,
                        'created_at' => $session->completed_at,
                        'updated_at' => $session->completed_at,
                    ]);
                }
            }
        }
    }

    private function getCheckinNotes($painLevel): string
    {
        $notes = [
            1 => "Presque pas de douleur, exercice facile",
            2 => "Légère douleur, mais gérable",
            3 => "Douleur modérée, exercice réalisable",
            4 => "Douleur importante, difficulté à terminer",
            5 => "Douleur très forte, exercice difficile",
        ];

        return $notes[$painLevel] ?? "Exercice complété";
    }
}
