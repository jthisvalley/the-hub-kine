<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\ExerciseSession;
use App\Models\PatientProgramAssignment;
use App\Models\CheckIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PatientStatsController extends Controller
{
    /**
     * Get patient statistics
     */
    public function getStats()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneWeekAgo = Carbon::now()->subWeek();
        $oneMonthAgo = Carbon::now()->subMonth();

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

        // Calculate streak (consecutive days with at least one completed session)
        $streak = $this->calculateStreak($patient);

        // Calculate total points (simplified - in reality you'd have a points table)
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
        $todayStats = [
            'completed_sessions' => $todaySessions->where('status', 'completed')->count(),
            'total_sessions' => $todaySessions->count(),
            'average_pain_level' => $todaySessions->where('status', 'completed')->avg('pain_level'),
            'adherence_rate' => $todaySessions->count() > 0
                ? ($todaySessions->where('status', 'completed')->count() / $todaySessions->count()) * 100
                : 0,
        ];

        // Weekly stats
        $weeklyStats = [
            'total_sessions' => $weeklySessions->count(),
            'average_pain_level' => $weeklySessions->avg('pain_level'),
            'completion_rate' => $weeklySessions->count() > 0
                ? ($weeklySessions->where('status', 'completed')->count() / $weeklySessions->count()) * 100
                : 0,
        ];

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
                'program_progress' => $programProgress,
                'pain_trends' => $painTrends,
                'monthly_stats' => [
                    'total_sessions' => $monthlySessions->count(),
                    'average_pain_level' => round($monthlySessions->avg('pain_level'), 1),
                    'total_duration' => round($monthlySessions->sum('duration_minutes') / 60, 1), // hours
                ],
            ],
        ]);
    }

    /**
     * Calculate current streak
     */
    private function calculateStreak($patient)
    {
        $today = Carbon::today();
        $streak = 0;

        // Check if patient had a session today
        $hasSessionToday = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereDate('session_date', $today)
            ->exists();

        if ($hasSessionToday) {
            $streak = 1;

            // Check previous days
            for ($i = 1; $i <= 365; $i++) { // Max 1 year streak
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
        } else {
            // Check yesterday
            $yesterday = $today->copy()->subDay();
            $hasSessionYesterday = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereDate('session_date', $yesterday)
                ->exists();

            if ($hasSessionYesterday) {
                $streak = 1;

                // Check previous days before yesterday
                for ($i = 2; $i <= 365; $i++) {
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
        }

        return $streak;
    }

    /**
     * Calculate level based on points
     */
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

    /**
     * Get pain level trends
     */
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
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill missing days with null
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
}
