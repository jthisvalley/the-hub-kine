<?php

namespace Database\Seeders;

use App\Models\PatientProgramAssignment;
use App\Models\User;
use App\Models\Program;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PatientProgramAssignmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all active kines
        $kines = User::where('role', 'kine')->where('is_active', true)->get();

        foreach ($kines as $kine) {
            // Get kine's programs
            $kinePrograms = Program::where('kine_id', $kine->id)->get();

            // Get kine's assigned patients
            $patients = $kine->assignedPatients()->where('is_active', true)->get();

            if ($kinePrograms->isEmpty() || $patients->isEmpty()) {
                continue;
            }

            foreach ($patients as $patient) {
                // Assign 1-3 random programs to each patient
                $numPrograms = rand(1, 3);
                $programsToAssign = $kinePrograms->random(min($numPrograms, $kinePrograms->count()));

                if (!is_iterable($programsToAssign)) {
                    $programsToAssign = [$programsToAssign];
                }

                foreach ($programsToAssign as $program) {
                    // Check if assignment already exists
                    $existingAssignment = PatientProgramAssignment::where('patient_id', $patient->id)
                        ->where('program_id', $program->id)
                        ->first();

                    if (!$existingAssignment) {
                        $startDate = Carbon::now()->subDays(rand(7, 60));
                        $status = rand(0, 1) ? 'active' : 'completed';

                        PatientProgramAssignment::create([
                            'patient_id' => $patient->id,
                            'program_id' => $program->id,
                            'assigned_by' => $kine->id,
                            'started_at' => $startDate,
                            'completed_at' => $status === 'completed' ? $startDate->copy()->addDays(rand(14, 30)) : null,
                            'status' => $status,
                        ]);
                    }
                }
            }
        }

        // Create some exercise sessions for assigned programs
        $this->createExerciseSessions();

        $this->command->info('✅ Patient program assignments seeded successfully!');
    }

    private function createExerciseSessions(): void
    {
        $assignments = PatientProgramAssignment::with(['program.exercises'])
            ->where('status', 'active')
            ->get();

        foreach ($assignments as $assignment) {
            if (!$assignment->program || !$assignment->program->exercises) {
                continue;
            }

            // Create 5-15 exercise sessions for each assignment
            $numSessions = rand(5, 15);
            $exercises = $assignment->program->exercises;

            for ($i = 0; $i < $numSessions; $i++) {
                $exercise = $exercises->random();
                $sessionDate = Carbon::now()->subDays(rand(0, 30));

                \App\Models\ExerciseSession::create([
                    'patient_id' => $assignment->patient_id,
                    'exercise_id' => $exercise->id,
                    'program_assignment_id' => $assignment->id,
                    'session_date' => $sessionDate,
                    'planned_repetitions' => $exercise->reps,
                    'actual_repetitions' => rand($exercise->reps - 2, $exercise->reps + 2),
                    'pain_level' => rand(1, 8),
                    'difficulty' => $this->getRandomDifficulty(),
                    'comments' => $this->getRandomComment(),
                    'duration_minutes' => round($exercise->duration_seconds / 60),
                    'completed_at' => $sessionDate->copy()->addMinutes(rand(5, 15)),
                    'status' => 'completed',
                ]);
            }
        }
    }

    private function getRandomDifficulty(): string
    {
        $difficulties = ['easy', 'normal', 'hard', 'very_hard'];
        return $difficulties[array_rand($difficulties)];
    }

    private function getRandomComment(): string
    {
        $comments = [
            'Exercice réalisé sans douleur',
            'Légère douleur mais supportable',
            'Amélioration notable par rapport à la dernière fois',
            'Un peu difficile aujourd\'hui',
            'Très satisfait de la séance',
            'Besoin de plus de concentration',
            'Meilleure amplitude qu\'hier',
            'Un peu de fatigue musculaire',
            'Parfaitement exécuté',
            'Progression constante',
        ];
        return $comments[array_rand($comments)];
    }
}
