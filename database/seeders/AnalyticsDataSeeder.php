<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\PatientAnalytics;
use App\Models\ExerciseSession;
use App\Models\DailyCheckin;
use App\Models\CheckIn;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductRecommendation;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding analytics data...');

        // Get kine
        $kine = User::where('id', "3fbe6057-dfe0-45d8-8245-bb982326d1dc")->first();
        if (!$kine) {
            $this->command->error('No kine found. Please run UserSeeder first.');
            return;
        }

        // Get patients assigned to this kine
        $patients = $kine->assignedPatients()->take(20)->get();
        if ($patients->isEmpty()) {
            $this->command->error('No patients found for kine. Please run KinePatientAssignmentSeeder first.');
            return;
        }

        $this->seedPatientAnalytics($patients, $kine);
        $this->seedAppointmentsForAnalytics($patients, $kine);
        $this->seedExerciseSessionsForAnalytics($patients);
        $this->seedDailyCheckinsForAnalytics($patients);
        $this->seedInvoicesForAnalytics($patients, $kine);
            $this->seedOrdersForAnalytics($patients);
        $this->seedProductRecommendationsForAnalytics($patients);

        $this->command->info('Analytics data seeded successfully!');
    }

    /**
     * Seed PatientAnalytics with realistic data
     */
    private function seedPatientAnalytics($patients, $kine): void
    {
        $this->command->info('Seeding patient analytics...');

        $periods = [];

        // Create periods for the last 6 months
        for ($i = 5; $i >= 0; $i--) {
            $startDate = Carbon::now()->subMonths($i)->startOfMonth();
            $endDate = Carbon::now()->subMonths($i)->endOfMonth();
            $periods[] = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'period' => $startDate->format('Y-m'),
            ];
        }

        foreach ($patients as $patient) {
            $streakCurrent = rand(0, 21);
            $totalPoints = rand(100, 2000);

            foreach ($periods as $period) {
                // Simulate some variation in adherence
                $baseAdherence = rand(70, 95);
                $monthVariation = rand(-10, 10);
                $adherenceRate = min(max($baseAdherence + $monthVariation, 60), 98);

                // Calculate exercises based on adherence
                $totalExercises = rand(15, 40);
                $completedExercises = round($totalExercises * ($adherenceRate / 100));

                // Simulate pain and mobility improvements
                $averagePainLevel = rand(30, 70) / 10; // 3.0 to 7.0
                $averageMobilityLevel = rand(50, 90) / 10; // 5.0 to 9.0

                // Add some improvement over time
                $monthsFromStart = 5 - array_search($period, $periods);
                if ($monthsFromStart > 0) {
                    $improvementFactor = $monthsFromStart * 0.1;
                    $averagePainLevel = max(1.0, $averagePainLevel - ($improvementFactor * 2));
                    $averageMobilityLevel = min(10.0, $averageMobilityLevel + ($improvementFactor * 2));
                }

                PatientAnalytics::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'period' => $period['period'],
                    'total_exercises' => $totalExercises,
                    'completed_exercises' => $completedExercises,
                    'adherence_rate' => $adherenceRate,
                    'average_pain_level' => round($averagePainLevel, 1),
                    'average_mobility_level' => round($averageMobilityLevel, 1),
                    'streak_current' => $streakCurrent,
                    'total_points' => $totalPoints,
                    'start_date' => $period['start_date'],
                    'end_date' => $period['end_date'],
                    'created_at' => $period['end_date'],
                    'updated_at' => $period['end_date'],
                ]);

                // Update streak and points for next period
                $streakCurrent = rand(0, $streakCurrent + 7);
                $totalPoints += rand(50, 200);
            }
        }
    }

    /**
     * Seed appointments for analytics (last 3 months)
     */
    private function seedAppointmentsForAnalytics($patients, $kine): void
    {
        $this->command->info('Seeding appointments for analytics...');

        // Create slots for the last 3 months
        $startDate = Carbon::now()->subMonths(3)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        $timeSlots = [
            ['start' => 8, 'end' => 10, 'type' => 'Rééducation'],
            ['start' => 10, 'end' => 12, 'type' => 'Consultations'],
            ['start' => 14, 'end' => 16, 'type' => 'Suivi'],
            ['start' => 16, 'end' => 18, 'type' => 'Nouveaux'],
            ['start' => 18, 'end' => 20, 'type' => 'Urgences'],
        ];

        $slotTypes = ['consultation', 'follow_up', 'emergency', 'initial_evaluation', 'rehabilitation'];
        $statuses = ['scheduled', 'completed', 'cancelled'];

        // Generate 3 months of data
        $period = CarbonPeriod::create($startDate, '1 day', $endDate);

        foreach ($period as $date) {
            // Skip weekends for some days
            if ($date->isWeekend() && rand(1, 3) === 1) continue;

            // Create 4-8 slots per day
            $slotsPerDay = rand(4, 8);

            for ($i = 0; $i < $slotsPerDay; $i++) {
                $timeSlot = $timeSlots[array_rand($timeSlots)];
                $hour = rand($timeSlot['start'], $timeSlot['end'] - 1);
                $minute = rand(0, 1) ? '00' : '30';

                $slotStart = $date->copy()->setTime($hour, $minute);
                $slotEnd = $slotStart->copy()->addMinutes(45);

                $slot = AppointmentSlot::create([
                    'id' => Str::uuid(),
                    'kine_id' => $kine->id,
                    'start_time' => $slotStart,
                    'end_time' => $slotEnd,
                    'is_available' => false, // Mark as booked
                    'created_at' => $slotStart,
                    'updated_at' => $slotStart,
                ]);

                // Decide appointment status
                $status = $statuses[array_rand($statuses)];
                $patient = $patients->random();
                $type = $slotTypes[array_rand($slotTypes)];

                // Determine if cancelled
                $isCancelled = $status === 'cancelled';
                $cancellationNotes = $isCancelled ? $this->generateCancellationNote() : null;

                $appointment = Appointment::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'slot_id' => $slot->id,
                    'status' => $status,
                    'type' => $type,
                    'notes' => $cancellationNotes,
                    'location' => rand(0, 1) ? 'Cabinet' : 'En ligne',
                    'is_online' => rand(0, 1),
                    'price' => $this->getAppointmentPrice($type),
                    'color' => $this->getAppointmentColor($type),
                    'created_at' => $slotStart->subDays(rand(1, 7)),
                    'updated_at' => $slotStart,
                ]);

                // Create invoice for completed appointments
                if ($status === 'completed') {
                    $this->createInvoiceForAppointment($appointment, $patient, $kine);
                }
            }
        }
    }

    /**
     * Seed exercise sessions for analytics
     */
private function seedExerciseSessionsForAnalytics($patients): void
{
    $this->command->info('Seeding exercise sessions for analytics...');

    // Get existing program assignments for patients
    $programAssignments = \App\Models\PatientProgramAssignment::whereIn('patient_id', $patients->pluck('id'))
        ->get()
        ->keyBy('patient_id');
    $randomProgramAssignment = $programAssignments->values()->random();
$programId = $randomProgramAssignment->program_id;

    // Get existing exercises
    $exercises = \App\Models\Exercise::all();
    if ($exercises->isEmpty()) {
        $this->command->warn('No exercises found. Please run ExerciseSeeder first.');
        // Create some sample exercises if none exist
        $exercises = collect();
        $exerciseCategories = [
            ['name' => 'Flexion de genou', 'category' => 'Mobilité', 'duration' => 15, 'difficulty' => 'beginner'],
            ['name' => 'Élévation de jambe', 'category' => 'Renforcement', 'duration' => 20, 'difficulty' => 'intermediate'],
            ['name' => 'Étirement quadriceps', 'category' => 'Étirements', 'duration' => 10, 'difficulty' => 'beginner'],
            ['name' => 'Planche', 'category' => 'Stabilisation', 'duration' => 30, 'difficulty' => 'advanced'],
            ['name' => 'Respiration diaphragmatique', 'category' => 'Respiratoire', 'duration' => 15, 'difficulty' => 'beginner'],
            ['name' => 'Rotation d\'épaule', 'category' => 'Mobilité', 'duration' => 12, 'difficulty' => 'intermediate'],
            ['name' => 'Pont fessier', 'category' => 'Renforcement', 'duration' => 25, 'difficulty' => 'intermediate'],
            ['name' => 'Étirement ischio', 'category' => 'Étirements', 'duration' => 15, 'difficulty' => 'beginner'],
            ['name' => 'Équilibre sur une jambe', 'category' => 'Stabilisation', 'duration' => 20, 'difficulty' => 'advanced'],
            ['name' => 'Respiration profonde', 'category' => 'Respiratoire', 'duration' => 10, 'difficulty' => 'beginner'],
        ];

        foreach ($exerciseCategories as $ex) {
            $exercise = \App\Models\Exercise::create([
                'id' => Str::uuid(),
                'program_id' => $programId,
                'name' => $ex['name'],
                'description' => "Exercice de {$ex['category']} - {$ex['name']}",
                'category' => $ex['category'],
                'duration_seconds' => $ex['duration'],
                'difficulty' => $ex['difficulty'],
                'sets' => rand(2, 4),
                'reps' => rand(8, 15),
                'video_url' => null,
                'instructions' => "Instructions pour l'exercice {$ex['name']}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $exercises->push($exercise);
        }
    }

    $categories = ['Mobilité', 'Renforcement', 'Étirements', 'Stabilisation', 'Respiratoire'];
    $statuses = ['pending', 'completed', 'skipped', 'in_progress'];
    $difficultyEnum = ['easy', 'normal', 'very_hard'];

    $startDate = Carbon::now()->subMonths(3);
    $endDate = Carbon::now();

    foreach ($patients as $patient) {
        // Get program assignment for this patient
        $assignment = $programAssignments->get($patient->id);

        // Each patient does 15-35 exercise sessions in 3 months (about 1-3 per week)
        $sessionCount = rand(15, 35);

        // Track adherence rate (70-95% completion)
        $targetCompletionRate = rand(70, 95);
        $completedSessions = 0;

        // Create sessions with realistic patterns
        $sessionDates = [];
        for ($i = 0; $i < $sessionCount; $i++) {
            // Distribute sessions evenly over the period
            $daysOffset = round(($i / ($sessionCount - 1)) * 90);
            $date = $startDate->copy()->addDays($daysOffset);

            // Add some random variation (+/- 2 days)
            $date = $date->copy()->addDays(rand(-2, 2));

            // Ensure date is within range
            if ($date < $startDate) $date = $startDate->copy();
            if ($date > $endDate) $date = $endDate->copy();

            $sessionDates[] = $date;
        }

        // Sort dates chronologically
        sort($sessionDates);

        foreach ($sessionDates as $dateIndex => $date) {
            // Determine session time (morning, afternoon, evening)
            $hour = rand(8, 20);
            $minute = rand(0, 1) ? 0 : 30;
            $sessionTime = $date->copy()->setTime($hour, $minute);

            // Select exercise based on category
            $category = $categories[array_rand($categories)];
            $categoryExercises = $exercises->where('category', $category);
            $exercise = $categoryExercises->isNotEmpty() ? $categoryExercises->random() : $exercises->random();

            // Determine if completed based on target completion rate
            $shouldComplete = rand(1, 100) <= $targetCompletionRate;
            $status = $shouldComplete ? 'completed' : (rand(1, 100) <= 70 ? 'skipped' : 'cancelled');

            $plannedRepetitions = $exercise->reps * $exercise->sets;
            $actualRepetitions = $status === 'completed'
                ? rand(max(1, $plannedRepetitions - 3), $plannedRepetitions)
                : ($status === 'skipped' ? rand(0, round($plannedRepetitions * 0.5)) : 0);

            $durationMinutes = $status === 'completed'
                ? rand(max(5, $exercise->duration_seconds - 5), $exercise->duration_seconds + 5)
                : ($status === 'skipped' ? rand(1, round($exercise->duration_seconds * 0.5)) : 0);

            $completedAt = $status === 'completed'
                ? $sessionTime->copy()->addMinutes($durationMinutes)
                : null;

            $painLevel = $status === 'completed'
                ? $this->calculatePainLevel($dateIndex, $sessionCount, $category)
                : null;

            // Handle difficulty - since it's an enum in Exercise model, we need to convert for ExerciseSession
            // Assuming ExerciseSession also uses the same enum
            $difficulty = $status === 'completed'
                ? $this->calculateSessionDifficulty($painLevel, $exercise->difficulty)
                : $difficultyEnum[array_rand($difficultyEnum)]; // Default if not completed

            $comments = $this->generateExerciseComments($status, $painLevel, $category);

            $session = ExerciseSession::create([
                'id' => Str::uuid(),
                'patient_id' => $patient->id,
                'program_assignment_id' => $assignment ? $assignment->id : null,
                'exercise_id' => $exercise->id,
                'category' => $category,
                'session_date' => $date,
                'session_time' => $sessionTime,
                'planned_repetitions' => $plannedRepetitions,
                'actual_repetitions' => $actualRepetitions,
                'pain_level' => $painLevel,
                'difficulty' => $difficulty,
                'comments' => $comments,
                'duration_minutes' => $durationMinutes,
                'completed_at' => $completedAt,
                'status' => $status,
                'created_at' => $sessionTime,
                'updated_at' => $sessionTime,
            ]);

            if ($status === 'completed') {
                $completedSessions++;
            }

            // Create corresponding CheckIn for completed sessions
            if ($status === 'completed') {
                \App\Models\CheckIn::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'exercise_session_id' => $session->id,
                    'completed_at' => $completedAt,
                    'pain_level' => $painLevel,
                    'notes' => $comments,
                    'duration_seconds' => $durationMinutes * 60,
                    'created_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);
            }
        }

        // Calculate actual adherence rate
        $actualAdherence = $sessionCount > 0 ? ($completedSessions / $sessionCount) * 100 : 0;
        $this->command->info("Patient {$patient->fullName()}: {$completedSessions}/{$sessionCount} sessions completed ({$actualAdherence}% adherence)");
    }
}

/**
 * Helper methods for exercise sessions
 */
private function calculatePainLevel(int $sessionIndex, int $totalSessions, string $category): int
{
    // Pain tends to decrease over time as patient improves
    $progressFactor = $sessionIndex / max(1, $totalSessions - 1);

    // Base pain varies by category
    $basePainByCategory = [
        'Mobilité' => rand(2, 4),
        'Renforcement' => rand(3, 5),
        'Étirements' => rand(1, 3),
        'Stabilisation' => rand(4, 6),
        'Respiratoire' => rand(1, 2),
    ];

    $basePain = $basePainByCategory[$category] ?? rand(2, 4);

    // Pain decreases as patient progresses (20-60% reduction)
    $painReduction = $progressFactor * rand(20, 60) / 100;
    $painLevel = max(1, round($basePain * (1 - $painReduction)));

    // Add some random variation
    $painLevel += rand(-1, 1);
    $painLevel = min(max(1, $painLevel), 5); // Keep within 1-5 range

    return (int) $painLevel;
}

private function calculateSessionDifficulty(?int $painLevel, string $exerciseDifficulty): string
{
    if (!$painLevel) return 'easy';

    // Convert exercise difficulty to numeric for calculation
    $difficultyMap = [
        'easy' => 1,
        'normal' => 2,
        'very_hard' => 3
    ];

    $numericDifficulty = $difficultyMap[$exerciseDifficulty] ?? 1;

    // Difficulty perception increases with pain
    $perceivedDifficulty = $numericDifficulty + round($painLevel / 3);

    // Convert back to enum value
    if ($perceivedDifficulty <= 1.5) {
        return 'easy';
    } elseif ($perceivedDifficulty <= 2.5) {
        return 'normal';
    } else {
        return 'very_hard';
    }
}

private function generateExerciseComments(string $status, ?int $painLevel, string $category): string
{
    if ($status !== 'completed') {
        $skippedReasons = [
            "Pas le temps aujourd'hui",
            "Fatigue excessive",
            "Douleur trop importante",
            "Problème technique",
            "Oubli",
            "Autre priorité",
        ];
        return $skippedReasons[array_rand($skippedReasons)];
    }

    $commentsByPainLevel = [
        1 => ["Excellent, aucune douleur", "Très facile à réaliser", "Progrès significatif"],
        2 => ["Légère gêne mais gérable", "Exercice bien réalisé", "Amélioration notable"],
        3 => ["Douleur modérée pendant l'exercice", "Difficulté moyenne", "Persévérance nécessaire"],
        4 => ["Douleur importante", "Exercice difficile", "Besoin de pauses fréquentes"],
        5 => ["Douleur très forte", "Extrêmement difficile", "À revoir avec le kiné"],
    ];

    $categoryComments = [
        'Mobilité' => ["Amélioration de l'amplitude", "Mouvement plus fluide", "Sensation de légèreté"],
        'Renforcement' => ["Force en augmentation", "Meilleur contrôle musculaire", "Endurance améliorée"],
        'Étirements' => ["Sensation d'étirement agréable", "Muscles plus souples", "Tension réduite"],
        'Stabilisation' => ["Équilibre amélioré", "Meilleur contrôle postural", "Stabilité accrue"],
        'Respiratoire' => ["Respiration plus profonde", "Sensation de détente", "Meilleure oxygénation"],
    ];

    $painComments = $commentsByPainLevel[$painLevel] ?? $commentsByPainLevel[3];
    $categoryComment = $categoryComments[$category] ?? ["Exercice bien réalisé"];

    return $painComments[array_rand($painComments)] . ". " . $categoryComment[array_rand($categoryComment)];
}

    /**
     * Seed daily checkins for analytics
     */
    private function seedDailyCheckinsForAnalytics($patients): void
    {
        $this->command->info('Seeding daily checkins for analytics...');

        $startDate = Carbon::now()->subMonths(3);

        foreach ($patients as $patient) {
            // Patients check in 50-80% of days
            $checkinDays = rand(45, 75); // Out of 90 days

            for ($i = 0; $i < $checkinDays; $i++) {
                $date = $startDate->copy()->addDays($i);
                // Skip some days randomly
                if (rand(1, 100) <= 30) continue;

                // Simulate pain level improvement over time
                $daysFromStart = $i;
                $basePain = rand(40, 80) / 10; // 4.0 to 8.0
                $improvement = min($daysFromStart * 0.03, 3.0);
                $painLevel = max(1.0, $basePain - $improvement);

                DailyCheckin::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'checkin_date' => $date,
                    'overall_pain_level' => round($painLevel, 1),
                    'mood' => ['happy', 'neutral', 'tired', 'energetic'][rand(0, 3)],
                    'energy_level' => rand(1, 5),
                    'sleep_hours' => rand(5.0, 9.0),
                    'notes' => $this->generateCheckinNote($painLevel),
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    /**
     * Seed invoices for analytics
     */
    private function seedInvoicesForAnalytics($patients, $kine): void
    {
        $this->command->info('Seeding invoices for analytics...');

        $startDate = Carbon::now()->subMonths(6);

        foreach ($patients as $patient) {
            // Each patient has 3-8 invoices in 6 months
            $invoiceCount = rand(3, 8);

            for ($i = 0; $i < $invoiceCount; $i++) {
                $date = $startDate->copy()->addDays(rand(0, 180));
                $status = ['paid', 'pending', 'overdue'][rand(0, 2)];

                // Base price varies by service
                $serviceTypes = ['consultation', 'follow_up', 'rehabilitation', 'program'];
                $serviceType = $serviceTypes[rand(0, 3)];
                $baseAmount = $this->getServicePrice($serviceType);

                // Add some random adjustments
                $adjustments = rand(-20, 20);
                $totalAmount = $baseAmount + $adjustments;

                Invoice::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'kine_id' => $kine->id,
                    'invoice_number' => 'INV-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
                    'service_type' => $serviceType,
                    'amount' => $baseAmount,
                    'tax' => $baseAmount * 0.2, // 20% tax
                    'total_amount' => $totalAmount,
                    'status' => $status,
                    'due_date' => $date->copy()->addDays(30),
                    'paid_at' => $status === 'paid' ? $date->copy()->addDays(rand(0, 30)) : null,
                    'notes' => $serviceType . ' service',
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    /**
     * Seed orders for analytics
     */
    private function seedOrdersForAnalytics($patients): void
    {
        $this->command->info('Seeding orders for analytics...');

        $startDate = Carbon::now()->subMonths(6);
        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

        foreach ($patients as $patient) {
            // Each patient makes 1-4 orders
            $orderCount = rand(1, 4);

            for ($i = 0; $i < $orderCount; $i++) {
                $date = $startDate->copy()->addDays(rand(0, 180));
                $status = $statuses[rand(0, 4)];

                $subtotal = rand(50, 300);
                $shipping = rand(5, 15);
                $total = $subtotal + $shipping;

                Order::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
                    'status' => $status,
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shipping,
                    'total_amount' => $total,
                    'shipping_address' => json_encode([
                        'street' => '123 Main St',
                        'city' => 'Paris',
                        'postal_code' => '75000',
                        'country' => 'France',
                    ]),
                    'notes' => 'Order from marketplace',
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    /**
     * Seed product recommendations for analytics
     */
    private function seedProductRecommendationsForAnalytics($patients): void
    {
        $this->command->info('Seeding product recommendations for analytics...');

        $products = \App\Models\Product::take(10)->get();
        if ($products->isEmpty()) {
            $this->command->warn('No products found. Please run ProductSeeder first.');
            return;
        }

        $startDate = Carbon::now()->subMonths(3);

        foreach ($patients as $patient) {
            // Each patient gets 2-5 recommendations
            $recommendationCount = rand(2, 5);

            for ($i = 0; $i < $recommendationCount; $i++) {
                $date = $startDate->copy()->addDays(rand(0, 90));
                $product = $products->random();

                ProductRecommendation::create([
                    'id' => Str::uuid(),
                    'patient_id' => $patient->id,
                    'product_id' => $product->id,
                    'kine_id' => $patient->assignedKine->id ?? User::where('role', 'kine')->first()->id,
                    'notes' => $this->getRecommendationReason($product),
                    'priority' => ['low', 'medium', 'high'][rand(0, 2)],
                    'status' => ['pending', 'using', 'purchased', 'completed'][rand(0, 3)],
                    'assigned_date' => $date,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    /**
     * Helper methods
     */
    private function generateCancellationNote(): string
    {
        $reasons = [
            "Problème de santé soudain",
            "Contraintes familiales imprévues",
            "Problèmes de transport",
            "Raisons financières",
            "Double réservation",
            "Mauvaise communication",
            "Déplacement professionnel",
            "Problème avec l'enfant",
            "Oubli",
            "Problème météo",
        ];

        $keywords = [
            'Problème santé' => ['malade', 'santé', 'douleur', 'fièvre', 'grippe'],
            'Contraintes perso.' => ['famille', 'personnel', 'privé', 'urgence', 'enfant'],
            'Déplacements' => ['voyage', 'déplacement', 'trafic', 'transport'],
            'Motifs financiers' => ['financ', 'prix', 'coût', 'budget', 'argent'],
        ];

        return $reasons[array_rand($reasons)];
    }

    private function generateCheckinNote(float $painLevel): string
    {
        if ($painLevel >= 7) {
            return "Douleur importante aujourd'hui, difficulté à effectuer les exercices";
        } elseif ($painLevel >= 5) {
            return "Douleur modérée, mais peut faire la plupart des exercices";
        } else {
            return "Très peu de douleur aujourd'hui, tous les exercices effectués avec succès";
        }
    }

    private function getAppointmentPrice(string $type): float
    {
        return match($type) {
            'consultation' => 80.00,
            'follow_up' => 60.00,
            'emergency' => 100.00,
            'initial_evaluation' => 120.00,
            'rehabilitation' => 75.00,
            default => 70.00,
        };
    }

    private function getAppointmentColor(string $type): string
    {
        return match($type) {
            'consultation' => '#3b82f6',
            'follow_up' => '#10b981',
            'emergency' => '#ef4444',
            'initial_evaluation' => '#8b5cf6',
            'rehabilitation' => '#f59e0b',
            default => '#6b7280',
        };
    }

    private function getServicePrice(string $serviceType): float
    {
        return match($serviceType) {
            'consultation' => 80.00,
            'follow_up' => 60.00,
            'rehabilitation' => 75.00,
            'program' => 300.00,
            default => 70.00,
        };
    }

    private function getRecommendationReason($product): string
    {
        $reasons = [
            "Pour améliorer votre récupération après les séances",
            "Recommandé pour votre condition spécifique",
            "Aide à réduire la douleur articulaire",
            "Améliore la mobilité et la flexibilité",
            "Complément utile à votre programme d'exercices",
            "Avis très positifs d'autres patients",
            "Spécialement adapté à vos besoins",
        ];

        return $reasons[array_rand($reasons)];
    }

    private function createInvoiceForAppointment($appointment, $patient, $kine): void
    {
        Invoice::create([
            'id' => Str::uuid(),
            'patient_id' => $patient->id,
            'kine_id' => $kine->id,
            'appointment_id' => $appointment->id,
            'invoice_number' => 'INV-' . $appointment->created_at->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'service_type' => $appointment->type,
            'amount' => $appointment->price,
            'tax' => $appointment->price * 0.2,
            'total_amount' => $appointment->price * 1.2,
            'status' => 'paid',
            'due_date' => $appointment->created_at->copy()->addDays(30),
            'paid_at' => $appointment->created_at->copy()->addDays(rand(0, 15)),
            'created_at' => $appointment->created_at,
            'updated_at' => $appointment->created_at,
        ]);
    }
}
