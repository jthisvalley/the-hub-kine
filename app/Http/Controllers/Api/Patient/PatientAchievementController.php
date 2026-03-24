<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\ExerciseSession;
use App\Models\PatientProgramAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PatientAchievementController extends Controller
{
    /**
     * Get patient achievements
     */
    public function index(Request $request)
    {
        $patient = Auth::user();

        // In a real application, you would have an achievements table
        // For now, we'll calculate achievements based on session data

        $allSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->get();

        $today = Carbon::today();
        $oneWeekAgo = Carbon::now()->subWeek();
        $oneMonthAgo = Carbon::now()->subMonth();

        // Calculate which achievements are earned
        $achievements = [
            // Streak achievements
            [
                'id' => 'streak_3',
                'title' => '3 jours consécutifs',
                'description' => 'Complétez des exercices pendant 3 jours consécutifs',
                'icon' => 'Calendar',
                'earned' => $this->checkStreak($patient, 3),
                'points' => 50,
            ],
            [
                'id' => 'streak_7',
                'title' => '7 jours consécutifs',
                'description' => 'Complétez des exercices pendant 7 jours consécutifs',
                'icon' => 'Calendar',
                'earned' => $this->checkStreak($patient, 7),
                'points' => 100,
            ],
            [
                'id' => 'streak_30',
                'title' => '30 jours consécutifs',
                'description' => 'Complétez des exercices pendant 30 jours consécutifs',
                'icon' => 'Calendar',
                'earned' => $this->checkStreak($patient, 30),
                'points' => 500,
            ],

            // Session count achievements
            [
                'id' => 'sessions_10',
                'title' => '10 séances',
                'description' => 'Complétez 10 séances d\'exercices',
                'icon' => 'Target',
                'earned' => $allSessions->count() >= 10,
                'points' => 50,
                'progress' => min(100, ($allSessions->count() / 10) * 100),
            ],
            [
                'id' => 'sessions_50',
                'title' => '50 séances',
                'description' => 'Complétez 50 séances d\'exercices',
                'icon' => 'Target',
                'earned' => $allSessions->count() >= 50,
                'points' => 250,
                'progress' => min(100, ($allSessions->count() / 50) * 100),
            ],
            [
                'id' => 'sessions_100',
                'title' => '100 séances',
                'description' => 'Complétez 100 séances d\'exercices',
                'icon' => 'Target',
                'earned' => $allSessions->count() >= 100,
                'points' => 1000,
                'progress' => min(100, ($allSessions->count() / 100) * 100),
            ],

            // Pain reduction achievements
            [
                'id' => 'pain_reduction_2',
                'title' => 'Réduction de la douleur',
                'description' => 'Réduisez votre niveau de douleur moyen de 2 points',
                'icon' => 'Shield',
                'earned' => $this->checkPainReduction($patient, 2),
                'points' => 200,
                'progress' => $this->getPainReductionProgress($patient, 2),
            ],
            [
                'id' => 'pain_reduction_5',
                'title' => 'Maîtrise de la douleur',
                'description' => 'Réduisez votre niveau de douleur moyen de 5 points',
                'icon' => 'Shield',
                'earned' => $this->checkPainReduction($patient, 5),
                'points' => 500,
                'progress' => $this->getPainReductionProgress($patient, 5),
            ],

            // Program completion achievements
            [
                'id' => 'program_completion',
                'title' => 'Programme terminé',
                'description' => 'Terminez complètement un programme d\'exercices',
                'icon' => 'Trophy',
                'earned' => $this->checkProgramCompletion($patient),
                'points' => 300,
            ],

            // Consistency achievements
            [
                'id' => 'weekly_consistency',
                'title' => 'Régularité hebdomadaire',
                'description' => 'Complétez toutes vos séances prévues pendant une semaine',
                'icon' => 'CheckCircle',
                'earned' => $this->checkWeeklyConsistency($patient),
                'points' => 150,
            ],
            [
                'id' => 'monthly_consistency',
                'title' => 'Régularité mensuelle',
                'description' => 'Complétez toutes vos séances prévues pendant un mois',
                'icon' => 'CheckCircle',
                'earned' => $this->checkMonthlyConsistency($patient),
                'points' => 500,
            ],
        ];

        // Filter by earned status if requested
        if ($request->has('earned') && $request->earned === 'true') {
            $achievements = array_filter($achievements, function ($achievement) {
                return $achievement['earned'];
            });
        }

        // Paginate manually (in a real app, you'd use database pagination)
        $perPage = $request->per_page ?? 10;
        $page = $request->page ?? 1;
        $offset = ($page - 1) * $perPage;
        $paginatedAchievements = array_slice($achievements, $offset, $perPage);

        return response()->json([
            'success' => true,
            'data' => array_values($paginatedAchievements),
            'meta' => [
                'current_page' => (int)$page,
                'from' => $offset + 1,
                'last_page' => ceil(count($achievements) / $perPage),
                'per_page' => $perPage,
                'to' => min($offset + $perPage, count($achievements)),
                'total' => count($achievements),
            ],
        ]);
    }

    /**
     * Check if patient has a streak of X days
     */
    private function checkStreak($patient, $daysRequired)
    {
        $streak = 0;
        $today = Carbon::today();

        // Start from today and go backwards
        for ($i = 0; $i < $daysRequired; $i++) {
            $date = $today->copy()->subDays($i);

            $hasSession = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereDate('session_date', $date)
                ->exists();

            if ($hasSession) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak >= $daysRequired;
    }

    /**
     * Check pain reduction - FIXED: Use simpler logic
     */
    private function checkPainReduction($patient, $reductionRequired)
    {
        // Get sessions from first and last 7 days of treatment
        $firstWeekSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->orderBy('session_date')
            ->limit(7)
            ->get();

        $lastWeekSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->orderBy('session_date', 'desc')
            ->limit(7)
            ->get();

        if ($firstWeekSessions->isEmpty() || $lastWeekSessions->isEmpty()) {
            return false;
        }

        $firstWeekAvgPain = $firstWeekSessions->avg('pain_level');
        $lastWeekAvgPain = $lastWeekSessions->avg('pain_level');

        return ($firstWeekAvgPain - $lastWeekAvgPain) >= $reductionRequired;
    }

    /**
     * Get pain reduction progress - FIXED: Use simpler logic
     */
    private function getPainReductionProgress($patient, $reductionRequired)
    {
        $firstWeekSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->orderBy('session_date')
            ->limit(7)
            ->get();

        $lastWeekSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->orderBy('session_date', 'desc')
            ->limit(7)
            ->get();

        if ($firstWeekSessions->isEmpty() || $lastWeekSessions->isEmpty()) {
            return 0;
        }

        $firstWeekAvgPain = $firstWeekSessions->avg('pain_level');
        $lastWeekAvgPain = $lastWeekSessions->avg('pain_level');
        $actualReduction = $firstWeekAvgPain - $lastWeekAvgPain;

        return min(100, ($actualReduction / $reductionRequired) * 100);
    }

    /**
     * Check program completion
     */
    private function checkProgramCompletion($patient)
    {
        $programs = PatientProgramAssignment::with(['program.exercises'])
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->get();

        foreach ($programs as $program) {
            $totalExercises = $program->program->exercises->count();
            $completedSessions = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $program->id)
                ->where('status', 'completed')
                ->count();

            if ($completedSessions >= $totalExercises && $totalExercises > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check weekly consistency
     */
    private function checkWeeklyConsistency($patient)
    {
        $oneWeekAgo = Carbon::now()->subWeek();

        $scheduledSessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$oneWeekAgo, Carbon::now()])
            ->count();

        $completedSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneWeekAgo, Carbon::now()])
            ->count();

        return $scheduledSessions > 0 && $completedSessions === $scheduledSessions;
    }

    /**
     * Check monthly consistency
     */
    private function checkMonthlyConsistency($patient)
    {
        $oneMonthAgo = Carbon::now()->subMonth();

        $scheduledSessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$oneMonthAgo, Carbon::now()])
            ->count();

        $completedSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, Carbon::now()])
            ->count();

        return $scheduledSessions > 0 && $completedSessions === $scheduledSessions;
    }
}
