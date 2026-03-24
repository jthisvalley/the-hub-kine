<?php

namespace App\Services;

use App\Models\{
    User,
    ExerciseSession,
    LoyaltyPoints,
    PointsActivity,
    StreakTracker,
    Milestone,
    Achievement,
    PatientAchievement
};
use Carbon\Carbon;

class RewardSystemService
{
    private $dailyPoints = 10;
    private $weeklyBonus = 50;
    private $streakMultipliers = [
        3 => 1.1,   // 10% bonus for 3-day streak
        7 => 1.25,  // 25% bonus for 7-day streak
        14 => 1.5,  // 50% bonus for 14-day streak
        30 => 2.0,  // 100% bonus for 30-day streak
    ];

    public function recordExerciseCompletion(ExerciseSession $session)
    {
        $patient = $session->patient;
        $today = Carbon::today();

        // Get or create loyalty points record
        $loyaltyPoints = LoyaltyPoints::firstOrCreate(
            ['patient_id' => $patient->id],
            [
                'total_points' => 0,
                'available_points' => 0,
                'level' => 1,
                'streak_current' => 0,
                'streak_longest' => 0,
                'last_activity_date' => null,
                'last_exercise_date' => null,
                'exercises_completed_today' => 0,
            ]
        );

        // Check if this is first exercise today
        $isFirstExerciseToday = !$loyaltyPoints->last_exercise_date ||
                               $loyaltyPoints->last_exercise_date->lt($today);

        // Calculate base points
        $basePoints = $this->calculateBasePoints($session);

        // Calculate streak bonus
        $streakBonus = $this->calculateStreakBonus($loyaltyPoints, $today);

        // Calculate daily bonus (for first exercise of the day)
        $dailyBonus = $isFirstExerciseToday ? $this->dailyPoints : 0;

        // Calculate weekly bonus if applicable
        $weeklyBonus = $this->calculateWeeklyBonus($patient, $today);

        $totalPoints = $basePoints + $streakBonus + $dailyBonus + $weeklyBonus;

        // Update loyalty points
        $loyaltyPoints->total_points += $totalPoints;
        $loyaltyPoints->available_points += $totalPoints;
        $loyaltyPoints->exercises_completed_today += 1;
        $loyaltyPoints->last_activity_date = $today;
        $loyaltyPoints->last_exercise_date = $today;

        // Update streak
        $this->updateStreak($loyaltyPoints, $today);

        $loyaltyPoints->save();

        // Record activity
        PointsActivity::create([
            'patient_id' => $patient->id,
            'exercise_session_id' => $session->id,
            'points' => $totalPoints,
            'activity_type' => 'exercise_completion',
            'description' => "Completed exercise: {$session->exercise->name}",
            'streak_bonus' => $streakBonus,
            'daily_bonus' => $dailyBonus,
            'metadata' => [
                'base_points' => $basePoints,
                'streak_length' => $loyaltyPoints->streak_current,
                'is_first_exercise_today' => $isFirstExerciseToday,
            ]
        ]);

        // Check for milestone achievements
        $this->checkMilestones($patient, $loyaltyPoints);

        // Check for achievements
        $this->checkAchievements($patient, $session);

        return [
            'points_earned' => $totalPoints,
            'breakdown' => [
                'base' => $basePoints,
                'streak_bonus' => $streakBonus,
                'daily_bonus' => $dailyBonus,
                'weekly_bonus' => $weeklyBonus,
            ],
            'current_streak' => $loyaltyPoints->streak_current,
            'total_points' => $loyaltyPoints->total_points,
        ];
    }

    private function calculateBasePoints(ExerciseSession $session): int
    {
        // Base on exercise difficulty
        $difficultyMultiplier = match($session->exercise->difficulty ?? 'beginner') {
            'beginner' => 1,
            'intermediate' => 1.5,
            'advanced' => 2,
            default => 1
        };

        // Points based on completion percentage
        $completionRate = $session->actual_repetitions / max($session->planned_repetitions, 1);
        $completionMultiplier = min($completionRate, 1.5); // Cap at 150% for over-achievement

        return (int) round(10 * $difficultyMultiplier * $completionMultiplier);
    }

    private function calculateStreakBonus(LoyaltyPoints $loyaltyPoints, Carbon $today): int
    {
        if (!$loyaltyPoints->last_activity_date) {
            return 0;
        }

        $yesterday = $today->copy()->subDay();

        // Check if streak is broken
        if ($loyaltyPoints->last_activity_date->lt($yesterday)) {
            $loyaltyPoints->streak_current = 1;
            return 0;
        }

        // Apply streak multiplier
        $streak = $loyaltyPoints->streak_current;
        $multiplier = 1.0;

        foreach ($this->streakMultipliers as $days => $bonus) {
            if ($streak >= $days) {
                $multiplier = $bonus;
            }
        }

        return (int) round($this->dailyPoints * ($multiplier - 1));
    }

    private function updateStreak(LoyaltyPoints $loyaltyPoints, Carbon $today)
    {
        if (!$loyaltyPoints->last_activity_date) {
            $loyaltyPoints->streak_current = 1;
            return;
        }

        $yesterday = $today->copy()->subDay();

        if ($loyaltyPoints->last_activity_date->eq($yesterday)) {
            // Consecutive day
            $loyaltyPoints->streak_current += 1;
        } elseif ($loyaltyPoints->last_activity_date->lt($yesterday)) {
            // Streak broken
            $loyaltyPoints->streak_current = 1;
        }
        // Same day - streak doesn't increase but also doesn't break

        // Update longest streak if needed
        if ($loyaltyPoints->streak_current > $loyaltyPoints->streak_longest) {
            $loyaltyPoints->streak_longest = $loyaltyPoints->streak_current;
        }
    }

    private function calculateWeeklyBonus(User $patient, Carbon $today): int
    {
        $startOfWeek = $today->copy()->startOfWeek();
        $exercisesThisWeek = ExerciseSession::where('patient_id', $patient->id)
            ->where('completed_at', '>=', $startOfWeek)
            ->where('status', 'completed')
            ->count();

        // Award bonus for 5+ exercises in a week
        if ($exercisesThisWeek >= 5) {
            // Check if bonus already awarded this week
            $alreadyAwarded = PointsActivity::where('patient_id', $patient->id)
                ->where('activity_type', 'weekly_bonus')
                ->where('created_at', '>=', $startOfWeek)
                ->exists();

            if (!$alreadyAwarded) {
                return $this->weeklyBonus;
            }
        }

        return 0;
    }

    private function checkMilestones(User $patient, LoyaltyPoints $loyaltyPoints)
    {
        $milestones = [
            ['points' => 100, 'title' => 'First 100 Points'],
            ['points' => 500, 'title' => '500 Points Club'],
            ['points' => 1000, 'title' => 'Elite Member'],
            ['points' => 5000, 'title' => 'Master Athlete'],
        ];

        foreach ($milestones as $milestone) {
            if ($loyaltyPoints->total_points >= $milestone['points']) {
                $this->awardMilestone($patient, $milestone, $loyaltyPoints->total_points);
            }
        }

        // Check streak milestones
        $streakMilestones = [3, 7, 14, 30, 60, 90];
        foreach ($streakMilestones as $days) {
            if ($loyaltyPoints->streak_current >= $days) {
                $this->awardStreakMilestone($patient, $days, $loyaltyPoints->streak_current);
            }
        }
    }

    private function awardMilestone(User $patient, array $milestone, int $currentPoints)
    {
        Milestone::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'title' => $milestone['title'],
            ],
            [
                'description' => "Reached {$milestone['points']} loyalty points!",
                'type' => 'points',
                'achieved' => true,
                'achieved_date' => now(),
                'target_value' => $milestone['points'],
                'current_value' => $currentPoints,
                'icon' => '🏆',
            ]
        );
    }

    private function awardStreakMilestone(User $patient, int $days, int $currentStreak)
    {
        Milestone::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'title' => "{$days}-Day Streak",
            ],
            [
                'description' => "Maintained a {$currentStreak}-day exercise streak!",
                'type' => 'streak',
                'achieved' => true,
                'achieved_date' => now(),
                'target_value' => $days,
                'current_value' => $currentStreak,
                'icon' => '🔥',
            ]
        );
    }

    private function checkAchievements(User $patient, ExerciseSession $session)
    {
        $achievements = Achievement::where('type', 'exercise')
            ->where('category', $session->exercise->category_id)
            ->get();

        foreach ($achievements as $achievement) {
            $this->updateAchievementProgress($patient, $achievement, $session);
        }
    }

    private function updateAchievementProgress(User $patient, Achievement $achievement, ExerciseSession $session)
    {
        $patientAchievement = PatientAchievement::firstOrCreate(
            [
                'patient_id' => $patient->id,
                'achievement_id' => $achievement->id,
            ],
            [
                'unlocked' => false,
                'progress' => 0,
            ]
        );

        if (!$patientAchievement->unlocked) {
            $patientAchievement->progress += 1;

            if ($patientAchievement->progress >= $achievement->target_value) {
                $patientAchievement->unlocked = true;
                $patientAchievement->unlocked_at = now();

                // Award points for achievement
                $loyaltyPoints = LoyaltyPoints::firstOrCreate(
                    ['patient_id' => $patient->id],
                    ['total_points' => 0, 'available_points' => 0, 'level' => 1]
                );

                $loyaltyPoints->total_points += $achievement->points;
                $loyaltyPoints->available_points += $achievement->points;
                $loyaltyPoints->save();

                PointsActivity::create([
                    'patient_id' => $patient->id,
                    'achievement_id' => $achievement->id,
                    'points' => $achievement->points,
                    'activity_type' => 'achievement_unlocked',
                    'description' => "Unlocked achievement: {$achievement->title}",
                ]);
            }

            $patientAchievement->save();
        }
    }

    public function getPatientStats($patientId)
    {
        $loyaltyPoints = LoyaltyPoints::firstOrCreate(
            ['patient_id' => $patientId],
            ['total_points' => 0, 'available_points' => 0, 'level' => 1]
        );

        $today = Carbon::today();
        $startOfWeek = $today->copy()->startOfWeek();
        $startOfMonth = $today->copy()->startOfMonth();

        return [
            'points' => [
                'total' => $loyaltyPoints->total_points,
                'available' => $loyaltyPoints->available_points,
                'level' => $loyaltyPoints->level,
            ],
            'streaks' => [
                'current' => $loyaltyPoints->streak_current,
                'longest' => $loyaltyPoints->streak_longest,
                'last_activity' => $loyaltyPoints->last_activity_date,
            ],
            'today' => [
                'exercises_completed' => $loyaltyPoints->exercises_completed_today,
                'points_earned_today' => PointsActivity::where('patient_id', $patientId)
                    ->whereDate('created_at', $today)
                    ->sum('points'),
            ],
            'this_week' => [
                'exercises_completed' => ExerciseSession::where('patient_id', $patientId)
                    ->where('completed_at', '>=', $startOfWeek)
                    ->where('status', 'completed')
                    ->count(),
                'points_earned' => PointsActivity::where('patient_id', $patientId)
                    ->where('created_at', '>=', $startOfWeek)
                    ->sum('points'),
            ],
            'milestones' => Milestone::where('patient_id', $patientId)
                ->where('achieved', true)
                ->orderBy('achieved_date', 'desc')
                ->take(5)
                ->get(),
            'recent_achievements' => PatientAchievement::where('patient_id', $patientId)
                ->where('unlocked', true)
                ->with('achievement')
                ->orderBy('unlocked_at', 'desc')
                ->take(5)
                ->get(),
        ];
    }
}
