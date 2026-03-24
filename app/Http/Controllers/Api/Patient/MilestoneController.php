<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\ExerciseSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MilestoneController extends Controller
{
    /**
     * Get patient milestones
     */
    public function index()
    {
        $patient = Auth::user();

        // Calculate dynamic milestones based on patient data
        $milestones = $this->calculateMilestones($patient);

        // Get stored milestones
        $storedMilestones = Milestone::where('patient_id', $patient->id)
            ->get()
            ->map(function ($milestone) {
                return [
                    'id' => $milestone->id,
                    'title' => $milestone->title,
                    'description' => $milestone->description,
                    'type' => $milestone->type,
                    'achieved' => $milestone->achieved,
                    'achievedDate' => $milestone->achieved_date ? $milestone->achieved_date->format('Y-m-d') : null,
                    'targetValue' => $milestone->target_value,
                    'currentValue' => $milestone->current_value,
                    'icon' => $milestone->icon,
                ];
            });

        // Merge dynamic and stored milestones
        $allMilestones = array_merge($milestones, $storedMilestones->toArray());

        return response()->json([
            'success' => true,
            'data' => $allMilestones,
        ]);
    }

    /**
     * Calculate dynamic milestones
     */
    private function calculateMilestones($patient)
    {
        $milestones = [];

        // Streak milestones
        $streak = $this->calculateStreak($patient);
        $milestones[] = [
            'id' => 'streak_7',
            'title' => '7 jours consécutifs',
            'description' => '7 jours d\'exercices sans interruption',
            'type' => 'days',
            'achieved' => $streak >= 7,
            'achievedDate' => $streak >= 7 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 7,
            'currentValue' => min($streak, 7),
            'icon' => 'Calendar',
        ];

        $milestones[] = [
            'id' => 'streak_30',
            'title' => '30 jours consécutifs',
            'description' => '30 jours d\'exercices sans interruption',
            'type' => 'days',
            'achieved' => $streak >= 30,
            'achievedDate' => $streak >= 30 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 30,
            'currentValue' => min($streak, 30),
            'icon' => 'Calendar',
        ];

        // Exercise count milestones
        $totalExercises = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->count();

        $milestones[] = [
            'id' => 'exercises_50',
            'title' => '50 exercices complétés',
            'description' => '50 exercices de rééducation terminés',
            'type' => 'exercises',
            'achieved' => $totalExercises >= 50,
            'achievedDate' => $totalExercises >= 50 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 50,
            'currentValue' => min($totalExercises, 50),
            'icon' => 'CheckCircle',
        ];

        $milestones[] = [
            'id' => 'exercises_100',
            'title' => '100 exercices',
            'description' => '100 exercices de rééducation terminés',
            'type' => 'exercises',
            'achieved' => $totalExercises >= 100,
            'achievedDate' => $totalExercises >= 100 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 100,
            'currentValue' => min($totalExercises, 100),
            'icon' => 'CheckCircle',
        ];

        // Pain reduction milestone
        $painImprovement = $this->calculatePainImprovement($patient);
        $milestones[] = [
            'id' => 'pain_reduction_50',
            'title' => 'Réduction douleur 50%',
            'description' => 'Réduire la douleur de moitié depuis le début',
            'type' => 'improvement',
            'achieved' => $painImprovement >= 50,
            'achievedDate' => $painImprovement >= 50 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 50,
            'currentValue' => min($painImprovement, 100),
            'icon' => 'TrendingDown',
        ];

        // Session count milestone
        $milestones[] = [
            'id' => 'sessions_20',
            'title' => '20 séances',
            'description' => '20 séances de kinésithérapie complétées',
            'type' => 'sessions',
            'achieved' => $totalExercises >= 20,
            'achievedDate' => $totalExercises >= 20 ? Carbon::now()->format('Y-m-d') : null,
            'targetValue' => 20,
            'currentValue' => min($totalExercises, 20),
            'icon' => 'Activity',
        ];

        return $milestones;
    }

    /**
     * Calculate streak
     */
    private function calculateStreak($patient)
    {
        $today = Carbon::today();
        $streak = 0;

        $hasSessionToday = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', $today)
            ->exists();

        if ($hasSessionToday) {
            $streak = 1;
            for ($i = 1; $i <= 365; $i++) {
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
        }

        return $streak;
    }

    /**
     * Calculate pain improvement
     */
    private function calculatePainImprovement($patient)
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        $oneMonthAgo = Carbon::now()->subMonth();

        $firstMonthSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$threeMonthsAgo, $threeMonthsAgo->copy()->addMonth()])
            ->get();

        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, Carbon::now()])
            ->get();

        if ($firstMonthSessions->isEmpty() || $recentSessions->isEmpty()) {
            return 0;
        }

        $initialPain = $firstMonthSessions->avg('pain_level');
        $currentPain = $recentSessions->avg('pain_level');

        if ($initialPain == 0) return 0;

        $improvement = (($initialPain - $currentPain) / $initialPain) * 100;
        return max(0, round($improvement));
    }
}
