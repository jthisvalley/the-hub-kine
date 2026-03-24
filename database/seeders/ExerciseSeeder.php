<?php
// database/seeders/ExerciseSeeder.php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Program;
use Illuminate\Database\Seeder;

class ExerciseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $programs = Program::all();

        $exercises = [
            // Lombalgie exercises
            [
                'name' => 'Étirement des ischio-jambiers',
                'description' => 'Assis au sol, une jambe tendue, l\'autre pliée. Se pencher doucement vers la jambe tendue.',
                'video_url' => 'https://www.youtube.com/watch?v=example1',
                'duration_seconds' => 300,
                'sets' => 3,
                'reps' => 10,
                'rest_seconds' => 60,
            ],
            [
                'name' => 'Gainage abdominal',
                'description' => 'En appui sur les avant-bras et les pieds, maintenir le corps aligné.',
                'video_url' => 'https://www.youtube.com/watch?v=example2',
                'duration_seconds' => 180,
                'sets' => 3,
                'reps' => 5,
                'rest_seconds' => 90,
            ],
            [
                'name' => 'Rotation du bassin',
                'description' => 'Allongé sur le dos, genoux pliés, faire des rotations douces du bassin.',
                'video_url' => 'https://www.youtube.com/watch?v=example3',
                'duration_seconds' => 240,
                'sets' => 2,
                'reps' => 15,
                'rest_seconds' => 45,
            ],

            // Genou exercises
            [
                'name' => 'Extension de genou assis',
                'description' => 'Assis sur une chaise, tendre la jambe lentement et maintenir.',
                'video_url' => 'https://www.youtube.com/watch?v=example4',
                'duration_seconds' => 360,
                'sets' => 4,
                'reps' => 12,
                'rest_seconds' => 60,
            ],
            [
                'name' => 'Flexion de genou allongé',
                'description' => 'Allongé sur le ventre, plier le genou en rapprochant le talon de la fesse.',
                'video_url' => 'https://www.youtube.com/watch?v=example5',
                'duration_seconds' => 300,
                'sets' => 3,
                'reps' => 15,
                'rest_seconds' => 45,
            ],

            // Épaules exercises
            [
                'name' => 'Pendulum de Codman',
                'description' => 'Penché en avant, laisser le bras balancer doucement en cercle.',
                'video_url' => 'https://www.youtube.com/watch?v=example6',
                'duration_seconds' => 180,
                'sets' => 2,
                'reps' => 20,
                'rest_seconds' => 30,
            ],
            [
                'name' => 'Étirement du pectoral',
                'description' => 'Debout dans un encadrement de porte, avancer doucement le corps.',
                'video_url' => 'https://www.youtube.com/watch?v=example7',
                'duration_seconds' => 240,
                'sets' => 3,
                'reps' => 10,
                'rest_seconds' => 45,
            ],

            // Cheville exercises
            [
                'name' => 'Alphabet de la cheville',
                'description' => 'Assis, écrire l\'alphabet avec le pied en mobilisant la cheville.',
                'video_url' => 'https://www.youtube.com/watch?v=example8',
                'duration_seconds' => 300,
                'sets' => 2,
                'reps' => 1,
                'rest_seconds' => 60,
            ],
            [
                'name' => 'Équilibre sur une jambe',
                'description' => 'Debout sur une jambe, maintenir l\'équilibre les yeux ouverts puis fermés.',
                'video_url' => 'https://www.youtube.com/watch?v=example9',
                'duration_seconds' => 180,
                'sets' => 3,
                'reps' => 5,
                'rest_seconds' => 60,
            ],
        ];

        foreach ($programs as $program) {
            // Add 3-6 exercises to each program
            $numExercises = rand(3, 6);
            $selectedExercises = array_rand($exercises, min($numExercises, count($exercises)));

            if (!is_array($selectedExercises)) {
                $selectedExercises = [$selectedExercises];
            }

            $orderIndex = 1;
            foreach ($selectedExercises as $index) {
                $exerciseData = $exercises[$index];

                Exercise::create([
                    'program_id' => $program->id,
                    'name' => $exerciseData['name'],
                    'description' => $exerciseData['description'],
                    'video_url' => $exerciseData['video_url'],
                    'duration_seconds' => $exerciseData['duration_seconds'],
                    'sets' => $exerciseData['sets'],
                    'reps' => $exerciseData['reps'],
                    'rest_seconds' => $exerciseData['rest_seconds'],
                    'order_index' => $orderIndex++,
                ]);
            }
        }

        $this->command->info('✅ Exercises seeded successfully!');
    }
}
