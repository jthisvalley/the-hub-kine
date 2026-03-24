<?php

namespace Database\Factories;

use App\Models\PatientProgressReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientProgressReportFactory extends Factory
{
    protected $model = PatientProgressReport::class;

    public function definition()
    {
        $reportDate = $this->faker->dateTimeBetween('-6 months', 'now');
        $painStart = $this->faker->randomFloat(2, 3, 8);
        $painCurrent = $painStart - $this->faker->randomFloat(2, 0.5, 3);
        $mobilityStart = $this->faker->randomFloat(2, 2, 6);
        $mobilityCurrent = $mobilityStart + $this->faker->randomFloat(2, 0.5, 3);

        return [
            'patient_id' => User::where('role', 'patient')->inRandomOrder()->first()->id,
            'kine_id' => User::where('role', 'kine')->inRandomOrder()->first()->id,
            'title' => $this->faker->randomElement([
                'Bilan mensuel',
                'Évaluation de progression',
                'Rapport trimestriel',
                'Bilan post-traitement',
                'Évaluation complète'
            ]),
            'summary' => $this->faker->paragraph,
            'report_date' => $reportDate,
            'report_type' => $this->faker->randomElement(['monthly', 'quarterly', 'on_demand', 'post_treatment']),
            'status' => 'published',

            // Pain metrics
            'pain_level_start' => $painStart,
            'pain_level_current' => $painCurrent,
            'pain_improvement' => $painStart - $painCurrent,

            // Mobility metrics
            'mobility_score_start' => $mobilityStart,
            'mobility_score_current' => $mobilityCurrent,
            'mobility_improvement' => $mobilityCurrent - $mobilityStart,

            // Other metrics
            'adherence_rate' => $this->faker->randomFloat(2, 60, 98),
            'strength_improvement' => $this->faker->randomFloat(2, 5, 25),
            'flexibility_improvement' => $this->faker->randomFloat(2, 5, 20),

            // Session statistics
            'total_sessions' => $this->faker->numberBetween(10, 30),
            'completed_sessions' => function (array $attributes) {
                return $attributes['total_sessions'] - $this->faker->numberBetween(0, 5);
            },
            'missed_sessions' => function (array $attributes) {
                return $attributes['total_sessions'] - $attributes['completed_sessions'];
            },
            'average_session_duration' => $this->faker->numberBetween(20, 60),

            // Feedback
            'kine_observations' => $this->faker->paragraphs(3, true),
            'kine_recommendations' => $this->faker->paragraphs(2, true),
            'next_steps' => $this->faker->paragraphs(2, true),
            'patient_comments' => $this->faker->optional()->paragraph,
            'patient_satisfaction' => $this->faker->numberBetween(3, 5),

            // Attachments
            'attachments' => $this->faker->optional()->passthrough([
                [
                    [
                        'name' => 'exercises.pdf',
                        'url' => '/storage/reports/exercises.pdf',
                        'type' => 'pdf',
                        'size' => 1024000,
                    ]
                ]
            ]),
        ];
    }

    public function published()
    {
        return $this->state([
            'status' => 'published',
        ]);
    }

    public function draft()
    {
        return $this->state([
            'status' => 'draft',
        ]);
    }

    public function forPatient($patientId)
    {
        return $this->state([
            'patient_id' => $patientId,
        ]);
    }

    public function forKine($kineId)
    {
        return $this->state([
            'kine_id' => $kineId,
        ]);
    }
}
