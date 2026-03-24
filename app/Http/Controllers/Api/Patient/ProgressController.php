<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\ExerciseSession;
use App\Models\PatientProgramAssignment;
use App\Models\ProgressReport;
use App\Models\PatientGoal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class ProgressController extends Controller
{
    /**
     * Get comprehensive progress statistics
     */
    public function getStats()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneWeekAgo = Carbon::now()->subWeek();
        $oneMonthAgo = Carbon::now()->subMonth();
        $threeMonthsAgo = Carbon::now()->subMonths(3);

        // Get all sessions
        $allSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->get();

        // Get today's sessions
        $todaySessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereDate('session_date', today())
            ->get();

        // Get weekly sessions
        $weeklySessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneWeekAgo, $now])
            ->get();

        // Get monthly sessions
        $monthlySessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, $now])
            ->get();

        // Calculate adherence rate (sessions completed / sessions scheduled in last 30 days)
        $last30Days = Carbon::now()->subDays(30);
        $scheduledSessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$last30Days, $now])
            ->count();

        $completedSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$last30Days, $now])
            ->count();

        $adherenceRate = $scheduledSessions > 0
            ? ($completedSessions / $scheduledSessions) * 100
            : 100;

        // Calculate streak
        $streak = $this->calculateStreak($patient);

        // Calculate total points (from your existing PatientStatsController)
        $totalPoints = $allSessions->count() * 10;
        $weeklyPoints = $weeklySessions->count() * 10;

        // Calculate level based on points
        $levelData = $this->calculateLevel($totalPoints);

        // Get program progress
        $programs = PatientProgramAssignment::with(['program.exercises'])
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->get();

        $programProgress = $programs->map(function ($program) use ($patient) {
            $totalExercises = $program->program->exercises->count();
            $completedSessions = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $program->id)
                ->where('status', 'completed')
                ->count();
            $progress = $totalExercises > 0 ? ($completedSessions / $totalExercises) * 100 : 0;

            return [
                'program_id' => $program->id,
                'program_name' => $program->program->name,
                'progress' => round($progress, 2),
                'completed_exercises' => $completedSessions,
                'total_exercises' => $totalExercises,
            ];
        });

        // Pain level trends
        $painTrends = $this->getPainTrends($patient);

        // Today's stats
        $todayCompleted = $todaySessions->where('status', 'completed');
        $todayStats = [
            'completed_sessions' => $todayCompleted->count(),
            'total_sessions' => $todaySessions->count(),
            'average_pain_level' => $todayCompleted->avg('pain_level') ?? 0,
            'adherence_rate' => $todaySessions->count() > 0
                ? ($todayCompleted->count() / $todaySessions->count()) * 100
                : 0,
        ];

        // Weekly stats
        $weeklyCompleted = $weeklySessions->where('status', 'completed');
        $weeklyStats = [
            'total_sessions' => $weeklySessions->count(),
            'average_pain_level' => round($weeklySessions->avg('pain_level') ?? 0, 1),
            'completion_rate' => $weeklySessions->count() > 0
                ? ($weeklyCompleted->count() / $weeklySessions->count()) * 100
                : 0,
        ];

        // Monthly stats
        $monthlyStats = [
            'total_sessions' => $monthlySessions->count(),
            'average_pain_level' => round($monthlySessions->avg('pain_level') ?? 0, 1),
            'total_duration' => round($monthlySessions->sum('duration_minutes') / 60, 1),
        ];

        // Calculate improvements
        $painImprovement = $this->calculatePainImprovement($patient);
        $mobilityImprovement = $this->calculateMobilityImprovement($patient);
        $strengthImprovement = $this->calculateStrengthImprovement($patient);

        // Get active goals progress
        $activeGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'in-progress')
            ->get();

        $goalsProgress = $activeGoals->map(function ($goal) {
            return [
                'id' => $goal->id,
                'title' => $goal->title,
                'progress' => $goal->progress_percentage,
                'target' => $goal->target_value,
                'current' => $goal->current_value,
                'unit' => $goal->unit,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'total_points' => $totalPoints,
                'weekly_points' => $weeklyPoints,
                'streak' => $streak,
                'level' => $levelData['level'],
                'next_level' => $levelData['next_level'],
                'points_to_next' => $levelData['points_to_next'],
                'completed_exercises' => $allSessions->count(),
                'total_sessions' => $allSessions->count(),
                'adherence_rate' => round($adherenceRate, 2),
                'today_stats' => $todayStats,
                'weekly_stats' => $weeklyStats,
                'monthly_stats' => $monthlyStats,
                'program_progress' => $programProgress,
                'pain_trends' => $painTrends,
                'pain_improvement' => $painImprovement,
                'mobility_improvement' => $mobilityImprovement,
                'strength_improvement' => $strengthImprovement,
                'goals_progress' => $goalsProgress,
                'active_goals_count' => $activeGoals->count(),
                'goals_completion_rate' => $activeGoals->count() > 0
                    ? round($activeGoals->avg('progress_percentage'), 1)
                    : 0,
            ],
        ]);
    }

    /**
     * Get detailed progress metrics
     */
    public function getMetrics()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneMonthAgo = Carbon::now()->subMonth();
        $threeMonthsAgo = Carbon::now()->subMonths(3);

        // Recent sessions for calculations
        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, $now])
            ->get();

        $previousSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$threeMonthsAgo, $oneMonthAgo])
            ->get();

        // Pain metrics
        $currentPain = $recentSessions->avg('pain_level') ?? 0;
        $previousPain = $previousSessions->avg('pain_level') ?? $currentPain;
        $painImprovement = $previousPain - $currentPain;

        // Mobility metrics (based on exercise completion rate)
        $currentMobility = $this->calculateMobilityScore($patient);
        $previousMobility = $this->calculateHistoricalMobilityScore($patient, $threeMonthsAgo, $oneMonthAgo);
        $mobilityImprovement = $currentMobility - $previousMobility;

        // Adherence metrics
        $currentAdherence = $this->calculateAdherenceRate($patient, $oneMonthAgo, $now);
        $previousAdherence = $this->calculateAdherenceRate($patient, $threeMonthsAgo, $oneMonthAgo);

        // Strength metrics (simplified - based on completed sessions and pain)
        $currentStrength = $this->calculateStrengthScore($patient);
        $previousStrength = $this->calculateHistoricalStrengthScore($patient, $threeMonthsAgo, $oneMonthAgo);
        $strengthImprovement = $currentStrength - $previousStrength;

        // Get target values from active goals
        $painGoal = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'pain_level')
            ->where('status', 'in-progress')
            ->first();

        $mobilityGoal = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'mobility_score')
            ->where('status', 'in-progress')
            ->first();

        $adherenceGoal = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'adherence_rate')
            ->where('status', 'in-progress')
            ->first();

        $strengthGoal = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'strength_score')
            ->where('status', 'in-progress')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'pain' => [
                    'current' => round($currentPain, 1),
                    'target' => $painGoal ? (float) $painGoal->target_value : 2,
                    'trend' => $painImprovement > 0 ? 'down' : ($painImprovement < 0 ? 'up' : 'neutral'),
                    'improvement' => round($painImprovement, 1),
                    'unit' => '/10',
                ],
                'mobility' => [
                    'current' => round($currentMobility, 1),
                    'target' => $mobilityGoal ? (float) $mobilityGoal->target_value : 9,
                    'trend' => $mobilityImprovement > 0 ? 'up' : ($mobilityImprovement < 0 ? 'down' : 'neutral'),
                    'improvement' => round($mobilityImprovement, 1),
                    'unit' => '/10',
                ],
                'adherence' => [
                    'current' => round($currentAdherence, 1),
                    'target' => $adherenceGoal ? (float) $adherenceGoal->target_value : 90,
                    'trend' => $currentAdherence > $previousAdherence ? 'up' : ($currentAdherence < $previousAdherence ? 'down' : 'neutral'),
                    'improvement' => round($currentAdherence - $previousAdherence, 1),
                    'unit' => '%',
                ],
                'strength' => [
                    'current' => round($currentStrength, 1),
                    'target' => $strengthGoal ? (float) $strengthGoal->target_value : 80,
                    'trend' => $strengthImprovement > 0 ? 'up' : ($strengthImprovement < 0 ? 'down' : 'neutral'),
                    'improvement' => round($strengthImprovement, 1),
                    'unit' => '%',
                ],
            ],
        ]);
    }

    /**
     * Get pain data trends
     */
    public function getPainData(Request $request)
    {
        $patient = Auth::user();
        $days = $request->get('days', 7);
        $startDate = Carbon::now()->subDays($days - 1);

        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', $startDate)
            ->select(
                DB::raw('DATE(session_date) as date'),
                DB::raw('AVG(pain_level) as avg_pain'),
                DB::raw('AVG(actual_repetitions) / AVG(planned_repetitions) * 100 as mobility_score'),
                DB::raw('COUNT(*) as session_count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill missing days
        $painData = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $session = $sessions->where('date', $date->format('Y-m-d'))->first();

            $painData[] = [
                'date' => $date->format('d/m'),
                'pain' => $session ? round($session->avg_pain, 1) : null,
                'mobility' => $session ? round(min(10, $session->mobility_score / 10), 1) : null,
                'adherence' => $session ? 100 : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $painData,
        ]);
    }

    /**
     * Get weekly progress data
     */
    public function getWeeklyProgress(Request $request)
    {
        $patient = Auth::user();
        $weeks = $request->get('weeks', 5);

        $weeklyProgress = [];
        $now = Carbon::now();

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $now->copy()->subWeeks($i)->startOfWeek();
            $weekEnd = $now->copy()->subWeeks($i)->endOfWeek();

            $exercises = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereBetween('session_date', [$weekStart, $weekEnd])
                ->count();

            // Calculate target based on patient's active goals
            $target = $this->calculateWeeklyTarget($patient, $weekStart, $weekEnd);

            $weeklyProgress[] = [
                'week' => 'S' . ($weeks - $i),
                'exercises' => $exercises,
                'target' => $target,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => array_reverse($weeklyProgress),
        ]);
    }

    /**
     * Get daily progress
     */
    public function getDailyProgress()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $startOfWeek = $now->copy()->startOfWeek();

        $dailyData = [];
        $daysOfWeek = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);

            // Skip future days
            if ($date > $now) {
                $dailyData[] = ['day' => $daysOfWeek[$i], 'value' => 0];
                continue;
            }

            $scheduled = ExerciseSession::where('patient_id', $patient->id)
                ->whereDate('session_date', $date)
                ->count();

            $completed = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereDate('session_date', $date)
                ->count();

            $adherence = $scheduled > 0 ? ($completed / $scheduled) * 100 : 0;

            $dailyData[] = [
                'day' => $daysOfWeek[$i],
                'value' => round($adherence),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $dailyData,
        ]);
    }

    /**
     * Get comparison data
     */
    public function getComparisonData()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneMonthAgo = Carbon::now()->subMonth();

        // Get patient's recent data
        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, $now])
            ->get();

        // In a real app, you would get average data from other patients
        // For now, we'll use realistic mock data based on patient's progress
        $patientAdherence = $this->calculateAdherenceRate($patient, $oneMonthAgo, $now);

        $comparisonData = [
            [
                'category' => 'Douleur',
                'current' => round($recentSessions->avg('pain_level') ?? 0, 1),
                'average' => 4.5, // Average from similar patients
                'max' => 10,
            ],
            [
                'category' => 'Mobilité',
                'current' => $this->calculateMobilityScore($patient),
                'average' => 5.5,
                'max' => 10,
            ],
            [
                'category' => 'Assiduité',
                'current' => round($patientAdherence, 1),
                'average' => 65, // Average adherence rate
                'max' => 100,
            ],
            [
                'category' => 'Régularité',
                'current' => $this->calculateConsistencyScore($patient),
                'average' => 60,
                'max' => 100,
            ],
            [
                'category' => 'Progression',
                'current' => $this->calculateOverallProgressScore($patient),
                'average' => 50,
                'max' => 100,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $comparisonData,
        ]);
    }

    /**
     * Helper Methods
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

    private function calculateLevel($points)
    {
        $levels = [
            ['name' => 'Bronze', 'min' => 0, 'max' => 999],
            ['name' => 'Silver', 'min' => 1000, 'max' => 2499],
            ['name' => 'Gold', 'min' => 2500, 'max' => 4999],
            ['name' => 'Platinum', 'min' => 5000, 'max' => 9999],
            ['name' => 'Diamond', 'min' => 10000, 'max' => PHP_INT_MAX],
        ];

        $currentLevel = 'Bronze';
        $nextLevel = 'Silver';
        $pointsToNext = 1000 - $points;

        foreach ($levels as $index => $level) {
            if ($points >= $level['min'] && $points <= $level['max']) {
                $currentLevel = $level['name'];
                if (isset($levels[$index + 1])) {
                    $nextLevel = $levels[$index + 1]['name'];
                    $pointsToNext = $levels[$index + 1]['min'] - $points;
                } else {
                    $nextLevel = $level['name'];
                    $pointsToNext = 0;
                }
                break;
            }
        }

        return [
            'level' => $currentLevel,
            'next_level' => $nextLevel,
            'points_to_next' => max(0, $pointsToNext),
        ];
    }

    private function getPainTrends($patient)
    {
        $last7Days = Carbon::now()->subDays(7);

        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', $last7Days)
            ->select(
                DB::raw('DATE(session_date) as date'),
                DB::raw('AVG(pain_level) as avg_pain'),
                DB::raw('COUNT(*) as session_count')
            )
            ->groupBy('session_date')
            ->orderBy('session_date')
            ->get();

        $trends = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $session = $sessions->where('date', $date)->first();

            $trends[] = [
                'date' => $date,
                'avg_pain' => $session ? round($session->avg_pain, 1) : null,
                'session_count' => $session ? $session->session_count : 0,
            ];
        }

        $firstDay = collect($trends)->first(function ($day) {
            return $day['avg_pain'] !== null;
        });

        $lastDay = collect($trends)->reverse()->first(function ($day) {
            return $day['avg_pain'] !== null;
        });

        $improvement = null;
        if ($firstDay && $lastDay) {
            $improvement = $firstDay['avg_pain'] - $lastDay['avg_pain'];
        }

        return [
            'daily_trends' => $trends,
            'weekly_avg_pain' => $sessions->avg('avg_pain'),
            'pain_improvement' => $improvement,
        ];
    }

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

        return round($initialPain - $currentPain, 1);
    }

    private function calculateMobilityScore($patient)
    {
        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', Carbon::now()->subMonth())
            ->get();

        if ($recentSessions->isEmpty()) {
            return 5;
        }

        $mobilityScore = $recentSessions->avg(function ($session) {
            if ($session->planned_repetitions == 0) return 0;
            return ($session->actual_repetitions / $session->planned_repetitions) * 10;
        });

        return min(10, round($mobilityScore, 1));
    }

    private function calculateHistoricalMobilityScore($patient, $startDate, $endDate)
    {
        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$startDate, $endDate])
            ->get();

        if ($sessions->isEmpty()) {
            return 5;
        }

        $score = $sessions->avg(function ($session) {
            if ($session->planned_repetitions == 0) return 0;
            return ($session->actual_repetitions / $session->planned_repetitions) * 10;
        });

        return min(10, round($score, 1));
    }

    private function calculateMobilityImprovement($patient)
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        $oneMonthAgo = Carbon::now()->subMonth();

        $previousScore = $this->calculateHistoricalMobilityScore($patient, $threeMonthsAgo, $oneMonthAgo);
        $recentScore = $this->calculateHistoricalMobilityScore($patient, $oneMonthAgo, Carbon::now());

        return round($recentScore - $previousScore, 1);
    }

    private function calculateAdherenceRate($patient, $startDate, $endDate)
    {
        $scheduled = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$startDate, $endDate])
            ->count();

        $completed = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$startDate, $endDate])
            ->count();

        return $scheduled > 0 ? ($completed / $scheduled) * 100 : 100;
    }

    private function calculateStrengthScore($patient)
    {
        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', Carbon::now()->subMonth())
            ->get();

        $baseScore = $recentSessions->count() * 2;
        $painBonus = max(0, 10 - ($recentSessions->avg('pain_level') ?? 0)) * 3;
        $consistencyBonus = $this->calculateStreak($patient) * 0.5;

        return min(100, round($baseScore + $painBonus + $consistencyBonus));
    }

    private function calculateHistoricalStrengthScore($patient, $startDate, $endDate)
    {
        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$startDate, $endDate])
            ->get();

        $baseScore = $sessions->count() * 2;
        $painBonus = max(0, 10 - ($sessions->avg('pain_level') ?? 0)) * 3;

        return min(100, round($baseScore + $painBonus));
    }

    private function calculateStrengthImprovement($patient)
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        $oneMonthAgo = Carbon::now()->subMonth();

        $previousScore = $this->calculateHistoricalStrengthScore($patient, $threeMonthsAgo, $oneMonthAgo);
        $recentScore = $this->calculateHistoricalStrengthScore($patient, $oneMonthAgo, Carbon::now());

        return round($recentScore - $previousScore, 1);
    }

    private function calculateWeeklyTarget($patient, $weekStart, $weekEnd)
    {
        // Get active goals for session count
        $sessionGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'session_count')
            ->where('status', 'in-progress')
            ->get();

        $target = 0;
        foreach ($sessionGoals as $goal) {
            // Distribute annual/monthly goal to weekly
            if ($goal->unit === 'sessions/year') {
                $target += round($goal->target_value / 52); // Weekly target
            } elseif ($goal->unit === 'sessions/month') {
                $target += round($goal->target_value / 4); // Weekly target
            } else {
                $target += $goal->target_value; // Direct weekly target
            }
        }

        return max(3, $target); // Minimum 3 sessions per week
    }

    private function calculateConsistencyScore($patient)
    {
        $last30Days = Carbon::now()->subDays(30);

        $scheduledDays = ExerciseSession::where('patient_id', $patient->id)
            ->whereDate('session_date', '>=', $last30Days)
            ->select(DB::raw('DATE(session_date) as date'))
            ->groupBy('session_date')
            ->count();

        $completedDays = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', '>=', $last30Days)
            ->select(DB::raw('DATE(session_date) as date'))
            ->groupBy('session_date')
            ->count();

        return $scheduledDays > 0 ? round(($completedDays / $scheduledDays) * 100, 1) : 0;
    }

    private function calculateOverallProgressScore($patient)
    {
        $painImprovement = $this->calculatePainImprovement($patient);
        $mobilityImprovement = $this->calculateMobilityImprovement($patient);
        $adherence = $this->calculateAdherenceRate($patient, Carbon::now()->subMonth(), Carbon::now());
        $streak = $this->calculateStreak($patient);

        // Weighted score calculation
        $score = (
            ($painImprovement * 30) + // Pain improvement is most important (30%)
            ($mobilityImprovement * 25) + // Mobility improvement (25%)
            ($adherence * 0.25) + // Adherence (25%)
            (min($streak, 30) * 0.67) // Streak bonus up to 20% (max 30 days = 20%)
        );

        return min(100, max(0, round($score)));
    }
}
