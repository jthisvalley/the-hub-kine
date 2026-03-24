<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ExerciseSession;
use App\Models\PatientGoal;
use App\Models\PatientProgramAssignment;
use App\Models\User;
use App\Models\ProgressReportRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Achievement;
use App\Models\CheckIn;
use App\Models\LoyaltyPoints;
use App\Models\Milestone;
use App\Models\PainReport;
use App\Models\PointsActivity;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PatientDashboardController extends Controller
{
    /**
     * Get comprehensive dashboard statistics
     */
    public function getStats()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneWeekAgo = Carbon::now()->subWeek();
        $oneMonthAgo = Carbon::now()->subMonth();
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        $last30Days = Carbon::now()->subDays(30);

        // Get all completed exercise sessions
        $allSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->get();

        // Get today's sessions
        $todaySessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereDate('session_date', today())
            ->get();

        $todayCompleted = $todaySessions->where('status', 'completed');

        // Get weekly sessions
        $weeklySessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneWeekAgo->toDateString(), $now->toDateString()])
            ->get();

        // Get monthly sessions
        $monthlySessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo->toDateString(), $now->toDateString()])
            ->get();

        // Calculate adherence rate (last 30 days)
        $scheduledSessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$last30Days->toDateString(), $now->toDateString()])
            ->count();

        $completedSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$last30Days->toDateString(), $now->toDateString()])
            ->count();

        $adherenceRate = $scheduledSessions > 0
            ? round(($completedSessions / $scheduledSessions) * 100, 1)
            : 100;

        // Calculate streak
        $streak = $this->calculateStreak($patient);

        // Calculate points (10 points per completed session)
        $totalPoints = $allSessions->count() * 10;
        $weeklyPoints = $weeklySessions->count() * 10;

        // Calculate level based on points
        $levelData = $this->calculateLevel($totalPoints);

        // Get program progress with corrected calculation
        $programProgress = $this->getProgramProgress($patient);

        // Get pain trends
        $painTrends = $this->getPainTrends($patient);
        $painImprovement = $this->calculatePainImprovement($patient);
        $mobilityImprovement = $this->calculateMobilityImprovement($patient);
        $strengthImprovement = $this->calculateStrengthImprovement($patient);

        // Get active goals
        $activeGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'in-progress')
            ->get();

        // Count upcoming appointments
        $upcomingAppointments = Appointment::where('patient_id', $patient->id)
            ->whereHas('slot', function ($query) {
                $query->where('start_time', '>', now());
            })
            ->whereIn('status', ['confirmed', 'scheduled'])
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                // Points & Level
                'total_points' => $totalPoints,
                'weekly_points' => $weeklyPoints,
                'streak' => $streak,
                'level' => $levelData['level'],
                'next_level' => $levelData['next_level'],
                'points_to_next' => $levelData['points_to_next'],

                // Exercise Statistics
                'completed_exercises' => $allSessions->count(),
                'total_sessions' => $allSessions->count(),
                'adherence_rate' => $adherenceRate,

                // Today's Stats
                'today_stats' => [
                    'completed_sessions' => $todayCompleted->count(),
                    'total_sessions' => $todaySessions->count(),
                    'average_pain_level' => round($todayCompleted->avg('pain_level') ?? 0, 1),
                    'adherence_rate' => $todaySessions->count() > 0
                        ? round(($todayCompleted->count() / $todaySessions->count()) * 100, 1)
                        : 0,
                ],

                // Weekly Stats
                'weekly_stats' => [
                    'total_sessions' => $weeklySessions->count(),
                    'average_pain_level' => round($weeklySessions->avg('pain_level') ?? 0, 1),
                    'completion_rate' => $weeklySessions->count() > 0 ? 100 : 0,
                ],

                // Monthly Stats
                'monthly_stats' => [
                    'total_sessions' => $monthlySessions->count(),
                    'average_pain_level' => round($monthlySessions->avg('pain_level') ?? 0, 1),
                    'total_duration' => round($monthlySessions->sum('duration_minutes') / 60, 1),
                ],

                // Program Progress - FIXED calculation
                'program_progress' => $programProgress,

                // Improvements
                'pain_improvement' => round($painImprovement, 1),
                'mobility_improvement' => round($mobilityImprovement, 1),
                'strength_improvement' => round($strengthImprovement, 1),

                // Goals
                'goals_progress' => $activeGoals->map(function ($goal) {
                    return [
                        'id' => $goal->id,
                        'title' => $goal->title,
                        'progress' => $goal->progress_percentage,
                        'target' => (float) $goal->target_value,
                        'current' => (float) $goal->current_value,
                        'unit' => $goal->unit,
                    ];
                })->values(),
                'active_goals_count' => $activeGoals->count(),
                'goals_completion_rate' => $activeGoals->count() > 0
                    ? round($activeGoals->avg('progress_percentage'), 1)
                    : 0,

                // Upcoming appointments count
                'upcoming_appointments_count' => $upcomingAppointments,

                // Pain trends
                'pain_trends' => $painTrends['daily_trends'] ?? [],
                'weekly_avg_pain' => $painTrends['weekly_avg_pain'] ?? 0,
            ],
        ]);
    }

    /**
     * Get patient's exercise programs for dashboard
     */
    public function getPrograms(Request $request)
    {
        $patient = Auth::user();

        $query = PatientProgramAssignment::with([
            'program:id,name,description,difficulty,duration,image_url,image_alt',
            'program.exercises:id,program_id'
        ])
        ->where('patient_id', $patient->id)
        ->whereIn('status', ['active', 'pending']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Order by most recent
        $query->orderBy('created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 10);
        $assignments = $query->paginate($perPage);

        $data = $assignments->map(function ($assignment) use ($patient) {
            $totalExercises = $assignment->program->exercises->count();

            $completedSessions = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $assignment->id)
                ->where('status', 'completed')
                ->count();

            // FIXED: Progress should never exceed 100%
            $progress = $totalExercises > 0
                ? round(min(($completedSessions / $totalExercises) * 100, 100), 1)
                : 0;

            // Get next session
            $nextSession = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $assignment->id)
                ->where('status', 'scheduled')
                ->whereDate('session_date', '>=', today())
                ->orderBy('session_date')
                ->orderBy('session_time')
                ->first();

            return [
                'id' => $assignment->id,
                'program_id' => $assignment->program_id,
                'name' => $assignment->program->name ?? 'Programme d\'exercices',
                'title' => $assignment->program->name ?? 'Programme d\'exercices',
                'description' => $assignment->program->description ?? '',
                'difficulty' => $assignment->program->difficulty ?? 'Intermédiaire',
                'duration' => $assignment->program->duration ?? 30,
                'total_exercises' => $totalExercises,
                'exercise_count' => $totalExercises,
                'completed_exercises' => $completedSessions,
                'progress' => $progress,
                'is_completed' => $progress >= 100,
                'today_completed' => ExerciseSession::where('patient_id', $patient->id)
                    ->where('program_assignment_id', $assignment->id)
                    ->where('status', 'completed')
                    ->whereDate('session_date', today())
                    ->count(),
                'next_session' => $nextSession ? $this->formatNextSession($nextSession) : null,
                'thumbnail_url' => $assignment->program->image_url,
                'thumbnail' => $assignment->program->image_url,
                'thumbnail_alt' => $assignment->program->image_alt ?? 'Programme d\'exercices',
                'status' => $assignment->status,
                'started_at' => $assignment->started_at?->format('Y-m-d'),
                'estimated_end_at' => $assignment->estimated_end_at?->format('Y-m-d'),
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
                'program' => $assignment->program,
                'assignedBy' => $assignment->assignedBy ? [
                    'name' => $assignment->assignedBy->name,
                    'specialty' => $assignment->assignedBy->specialty,
                    'avatar' => $assignment->assignedBy->avatar,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'from' => $assignments->firstItem(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'to' => $assignments->lastItem(),
                'total' => $assignments->total(),
            ],
            'links' => [
                'first' => $assignments->url(1),
                'last' => $assignments->url($assignments->lastPage()),
                'prev' => $assignments->previousPageUrl(),
                'next' => $assignments->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Get upcoming appointments for dashboard
     */
    public function getUpcomingAppointments(Request $request)
    {
        $patient = Auth::user();
        $limit = $request->get('limit', 5);

        $appointments = Appointment::where('patient_id', $patient->id)
            ->whereHas('slot', function ($query) {
                $query->where('start_time', '>', now());
            })
            ->whereIn('status', ['confirmed', 'scheduled', 'pending'])
            ->with([
                'slot.kine:id,first_name,last_name,email,avatar_url,phone',
                'slot.kine.kineProfile:user_id,specialty',
                'slot:id,start_time,end_time,kine_id'
            ])
            // FIXED: Use the existing scope but we need to ensure the relationship exists
            ->orderBySlotTime('asc')
            ->limit($limit)
            ->get()
            ->map(function ($appointment) {
                $kine = $appointment->slot->kine;

                // Handle avatar URL with public path
                $avatarUrl = $kine->avatar_url ?? null;
                if ($avatarUrl && !str_starts_with($avatarUrl, 'http://') && !str_starts_with($avatarUrl, 'https://')) {
                    $avatarUrl = asset($avatarUrl);
                }

                return [
                    'id' => $appointment->id,
                    'physiotherapist' => $kine ? [
                        'id' => $kine->id,
                        'name' => $kine->first_name . ' ' . $kine->last_name,
                        'first_name' => $kine->first_name,
                        'last_name' => $kine->last_name,
                        'specialty' => $kine->kineProfile->specialty ?? 'Kinésithérapeute',
                        'avatar_url' => $avatarUrl,
                        'avatar' => $avatarUrl,
                        'phone' => $kine->phone,
                        'email' => $kine->email,
                    ] : null,
                    'date' => $appointment->slot->start_time->format('Y-m-d'),
                    'time' => $appointment->slot->start_time->format('H:i'),
                    'duration' => $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time),
                    'session_type' => $this->getAppointmentTypeLabel($appointment->type),
                    'sessionType' => $this->getAppointmentTypeLabel($appointment->type),
                    'location' => $appointment->location,
                    'status' => $appointment->status,
                    'is_online' => (bool) $appointment->is_online,
                    'isOnline' => (bool) $appointment->is_online,
                    'notes' => $appointment->notes,
                    'video_link' => $appointment->video_link,
                    'videoLink' => $appointment->video_link,
                    'price' => $appointment->price ? (float) $appointment->price : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Get progress statistics
     */
    public function getProgressStats()
    {
        $patient = Auth::user();
        $now = Carbon::now();
        $oneMonthAgo = Carbon::now()->subMonth();
        $threeMonthsAgo = Carbon::now()->subMonths(3);

        // Get recent sessions
        $recentSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$oneMonthAgo, $now])
            ->get();

        // Get previous sessions for comparison
        $previousSessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereBetween('session_date', [$threeMonthsAgo, $oneMonthAgo])
            ->get();

        // Current metrics
        $currentPain = $recentSessions->avg('pain_level') ?? 0;
        $currentMobility = $this->calculateMobilityScore($patient);
        $currentAdherence = $this->calculateAdherenceRate($patient, $oneMonthAgo, $now);

        // Previous metrics
        $previousPain = $previousSessions->avg('pain_level') ?? $currentPain;
        $previousMobility = $this->calculateHistoricalMobilityScore($patient, $threeMonthsAgo, $oneMonthAgo);
        $previousAdherence = $this->calculateAdherenceRate($patient, $threeMonthsAgo, $oneMonthAgo);

        // Calculate trends
        $painTrend = round($previousPain - $currentPain, 1);
        $mobilityTrend = round($currentMobility - $previousMobility, 1);
        $adherenceTrend = round($currentAdherence - $previousAdherence, 1);

        // Calculate improvements
        $painImprovement = $this->calculatePainImprovement($patient);
        $mobilityImprovement = $this->calculateMobilityImprovement($patient);
        $strengthImprovement = $this->calculateStrengthImprovement($patient);

        return response()->json([
            'success' => true,
            'data' => [
                'pain' => round($currentPain, 1),
                'mobility' => round($currentMobility, 1),
                'adherence' => round($currentAdherence, 1),
                'pain_trend' => $painTrend,
                'mobility_trend' => $mobilityTrend,
                'adherence_trend' => $adherenceTrend,
                'pain_improvement' => round($painImprovement, 1),
                'mobility_improvement' => round($mobilityImprovement, 1),
                'strength_improvement' => round($strengthImprovement, 1),
            ],
        ]);
    }

    /**
     * Get pain data for charts
     */
    public function getPainData(Request $request)
    {
        $patient = Auth::user();
        $days = $request->get('days', 7);
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereNotNull('pain_level')
            ->whereBetween('session_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select(
                DB::raw('DATE(session_date) as date'),
                DB::raw('COALESCE(AVG(pain_level), 0) as avg_pain'),
                DB::raw('COALESCE(AVG(actual_repetitions) / NULLIF(AVG(planned_repetitions), 0) * 100, 0) as mobility_score'),
                DB::raw('COUNT(*) as session_count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $painData = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateString = $date->format('Y-m-d');
            $session = $sessions->get($dateString);

            $mobilityScore = $session ? $session->mobility_score : 0;
            $mobilityValue = $mobilityScore > 0 ? round(min(10, $mobilityScore / 10), 1) : null;

            $painData[] = [
                'date' => $date->format('d/m'),
                'pain' => $session ? round($session->avg_pain, 1) : null,
                'mobility' => $mobilityValue,
                'adherence' => $session ? 100 : 0,
                'session_count' => $session ? $session->session_count : 0,
                'full_date' => $dateString,
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
            $weekNumber = $weeks - $i;
            $weekStart = $now->copy()->subWeeks($i)->startOfWeek(Carbon::MONDAY);
            $weekEnd = $now->copy()->subWeeks($i)->endOfWeek(Carbon::SUNDAY);

            $exercises = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereBetween('session_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->count();

            // Calculate target based on patient's active goals or default to 7
            $target = $this->calculateWeeklyTarget($patient, $weekStart, $weekEnd);

            // For current week, cap target to realistic value
            if ($i === 0) {
                $target = min($target, 7);
            }

            $weeklyProgress[] = [
                'week' => 'S' . $weekNumber,
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
     * Report pain (quick action) with points and achievements
     */
    public function reportPain(Request $request)
    {
        $request->validate([
            'pain_level' => 'required|integer|min:1|max:10',
            'location' => 'required|string|max:100',
            'description' => 'nullable|string',
            'trigger' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:100',
            'medications' => 'nullable|array',
            'relieving_factors' => 'nullable|array',
            'worsening_factors' => 'nullable|array',
            'affects_sleep' => 'boolean',
            'affects_daily_activities' => 'boolean',
            'kine_id' => 'nullable|exists:users,id',
        ]);

        $patient = Auth::user();
        $today = Carbon::today();

        DB::beginTransaction();

        try {
            // Create the pain report
            $painReport = PainReport::create([
                'patient_id' => $patient->id,
                'kine_id' => $request->kine_id,
                'pain_level' => $request->pain_level,
                'location' => $request->location,
                'description' => $request->description,
                'trigger' => $request->trigger,
                'duration' => $request->duration,
                'medications' => $request->medications,
                'relieving_factors' => $request->relieving_factors,
                'worsening_factors' => $request->worsening_factors,
                'affects_sleep' => $request->affects_sleep ?? false,
                'affects_daily_activities' => $request->affects_daily_activities ?? false,
                'status' => 'pending',
                'metadata' => [
                    'source' => 'patient_dashboard',
                    'submitted_at' => now()->toDateTimeString(),
                ],
            ]);

            // Award points for pain reporting (base points)
            $pointsEarned = 5; // Base points for reporting pain

            // Check if this is the first pain report of the day (bonus)
            $hasReportedToday = PainReport::where('patient_id', $patient->id)
                ->whereDate('created_at', $today)
                ->where('id', '!=', $painReport->id)
                ->exists();

            $dailyBonus = 0;
            if (!$hasReportedToday) {
                $dailyBonus = 3; // First report of the day bonus
                $pointsEarned += $dailyBonus;
            }

            // Check streak for pain reporting
            $streakBonus = $this->calculatePainReportingStreak($patient, $today);
            $pointsEarned += $streakBonus;

            // Update or create loyalty points
            $loyaltyPoints = LoyaltyPoints::firstOrCreate(
                ['patient_id' => $patient->id],
                [
                    'total_points' => 0,
                    'available_points' => 0,
                    'level' => 1,
                    'streak_current' => 0,
                    'streak_longest' => 0,
                    'exercises_completed_today' => 0,
                ]
            );

            // Record points activity
            PointsActivity::create([
                'patient_id' => $patient->id,
                'points' => $pointsEarned,
                'activity_type' => 'pain_report',
                'description' => 'Signalement de douleur (niveau ' . $request->pain_level . ')',
                'streak_bonus' => $streakBonus,
                'daily_bonus' => $dailyBonus,
                'metadata' => [
                    'pain_level' => $request->pain_level,
                    'location' => $request->location,
                    'pain_report_id' => $painReport->id,
                ],
            ]);

            // Update loyalty points totals
            $loyaltyPoints->total_points += $pointsEarned;
            $loyaltyPoints->available_points += $pointsEarned;
            $loyaltyPoints->last_activity_date = $today;

            // Update level based on total points
            $loyaltyPoints->level = $this->calculateLevelFromPoints($loyaltyPoints->total_points);

            $loyaltyPoints->save();

            // Update any active goals related to pain
            $updatedGoals = [];
            PatientGoal::where('patient_id', $patient->id)
                ->where('metric_type', 'pain_level')
                ->where('status', 'in-progress')
                ->each(function ($goal) use ($request, &$updatedGoals) {
                    $goal->current_value = $request->pain_level;

                    // For pain goals, lower is better (target is usually lower than current)
                    if ($goal->target_value < $goal->current_value) {
                        // Pain increased, progress might decrease
                        $progress = (($goal->current_value - $request->pain_level) /
                                    ($goal->current_value - $goal->target_value)) * 100;
                        $goal->progress_percentage = max(0, min(100, $progress));
                    } else {
                        // Standard calculation
                        $progress = ($goal->current_value / $goal->target_value) * 100;
                        $goal->progress_percentage = min(100, $progress);
                    }

                    if ($goal->progress_percentage >= 100) {
                        $goal->status = 'completed';
                        $patient = Auth::user();
                        // Award achievement for completing goal
                        $this->checkGoalCompletionAchievement($patient);
                    }

                    $goal->save();
                    $updatedGoals[] = $goal->id;
                });

            // Check for pain-related achievements
            $this->checkPainAchievements($patient, $request->pain_level);

            // Update milestones
            $this->updatePainMilestones($patient, $request->pain_level);

            DB::commit();

            // Get updated loyalty data for response
            $updatedLoyalty = LoyaltyPoints::where('patient_id', $patient->id)->first();

            // Get level info
            $levelInfo = $this->getLevelInfo($updatedLoyalty->total_points ?? $pointsEarned);

            return response()->json([
                'success' => true,
                'message' => 'Douleur signalée avec succès',
                'data' => [
                    'pain_report' => $painReport->load('kine'),
                    'points_earned' => $pointsEarned,
                    'points_breakdown' => [
                        'base' => 5,
                        'daily_bonus' => $dailyBonus,
                        'streak_bonus' => $streakBonus,
                    ],
                    'total_points' => $updatedLoyalty->total_points ?? $pointsEarned,
                    'level' => $levelInfo['level'],
                    'next_level' => $levelInfo['next_level'],
                    'points_to_next' => $levelInfo['points_to_next'],
                    'updated_goals' => $updatedGoals,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Pain report error: ' . $e->getMessage(), [
                'patient_id' => $patient->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du signalement de la douleur',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Calculate streak for pain reporting
     */
    private function calculatePainReportingStreak($patient, $today)
    {
        $streak = 0;
        $checkDate = $today->copy();

        // Check consecutive days with pain reports
        for ($i = 1; $i <= 30; $i++) {
            $date = $checkDate->copy()->subDays($i);

            $hasReport = CheckIn::where('patient_id', $patient->id)
                ->whereDate('created_at', $date)
                ->exists();

            if ($hasReport) {
                $streak++;
            } else {
                break;
            }
        }

        // Bonus points based on streak length
        if ($streak >= 30) return 50;
        if ($streak >= 14) return 25;
        if ($streak >= 7) return 15;
        if ($streak >= 3) return 8;

        return 0;
    }

    /**
     * Check pain-related achievements
     */
    private function checkPainAchievements($patient, $painLevel)
    {
        // Get all pain reports
        $painReports = CheckIn::where('patient_id', $patient->id)
            ->orderBy('created_at')
            ->get();

        $totalReports = $painReports->count();
        $averagePain = $painReports->avg('pain_level');

        // Achievement: First pain report
        if ($totalReports == 1) {
            $this->unlockAchievement($patient, 'first_pain_report');
        }

        // Achievement: 10 pain reports
        if ($totalReports == 10) {
            $this->unlockAchievement($patient, 'pain_reporter_10');
        }

        // Achievement: 50 pain reports
        if ($totalReports == 50) {
            $this->unlockAchievement($patient, 'pain_reporter_50');
        }

        // Achievement: Pain reduction (if average pain is below 4)
        if ($averagePain < 4 && $totalReports >= 5) {
            $this->unlockAchievement($patient, 'pain_master');
        }

        // Achievement: Pain-free day (pain level 1)
        if ($painLevel == 1) {
            // Check if this is the first time
            $hasLowPainBefore = CheckIn::where('patient_id', $patient->id)
                ->where('pain_level', 1)
                ->where('id', '!=', $painReports->last()->id ?? 0)
                ->exists();

            if (!$hasLowPainBefore) {
                $this->unlockAchievement($patient, 'pain_free_day');
            }
        }
    }

    /**
     * Update pain-related milestones
     */
    private function updatePainMilestones($patient, $painLevel)
    {
        $today = Carbon::today();

        // Get or create milestones
        $milestone = Milestone::firstOrCreate(
            [
                'patient_id' => $patient->id,
                'type' => 'pain_tracking',
            ],
            [
                'title' => 'Suivi de la douleur',
                'description' => 'Nombre de jours avec suivi de la douleur',
                'target_value' => 30,
                'current_value' => 0,
                'icon' => 'Activity',
                'achieved' => false,
            ]
        );

        // Count unique days with pain reports
        $uniqueDays = CheckIn::where('patient_id', $patient->id)
            ->select(DB::raw('DATE(created_at) as date'))
            ->distinct()
            ->count();

        $milestone->current_value = $uniqueDays;

        if ($uniqueDays >= $milestone->target_value && !$milestone->achieved) {
            $milestone->achieved = true;
            $milestone->achieved_date = $today;

            // Award points for milestone
            $this->awardMilestonePoints($patient, $milestone);
        }

        $milestone->save();
    }

    /**
     * Unlock achievement for patient
     */
    private function unlockAchievement($patient, $achievementKey)
    {
        $achievements = [
            'first_pain_report' => [
                'title' => 'Premier signalement',
                'description' => 'Vous avez signalé votre douleur pour la première fois',
                'tier' => 'bronze',
                'points' => 10,
            ],
            'pain_reporter_10' => [
                'title' => 'Observateur attentif',
                'description' => '10 signalements de douleur',
                'tier' => 'silver',
                'points' => 50,
            ],
            'pain_reporter_50' => [
                'title' => 'Expert du suivi',
                'description' => '50 signalements de douleur',
                'tier' => 'gold',
                'points' => 200,
            ],
            'pain_master' => [
                'title' => 'Maîtrise de la douleur',
                'description' => 'Niveau de douleur moyen inférieur à 4',
                'tier' => 'platinum',
                'points' => 100,
            ],
            'pain_free_day' => [
                'title' => 'Jour sans douleur',
                'description' => 'Premier jour sans douleur',
                'tier' => 'gold',
                'points' => 75,
            ],
        ];

        if (!isset($achievements[$achievementKey])) {
            return;
        }

        $achievementData = $achievements[$achievementKey];

        // Find or create achievement
        $achievement = Achievement::firstOrCreate(
            [
                'title' => $achievementData['title'],
                'type' => 'milestone',
            ],
            [
                'description' => $achievementData['description'],
                'tier' => $achievementData['tier'],
                'points' => $achievementData['points'],
                'icon' => 'Award',
            ]
        );

        // Check if patient already has this achievement
        $hasAchievement = $patient->achievements()
            ->where('achievement_id', $achievement->id)
            ->where('unlocked', true)
            ->exists();

        if (!$hasAchievement) {
            // Award achievement
            $patient->achievements()->attach($achievement->id, [
                'unlocked' => true,
                'unlocked_at' => now(),
                'progress' => 100,
            ]);

            // Award points for achievement
            $loyaltyPoints = LoyaltyPoints::where('patient_id', $patient->id)->first();
            if ($loyaltyPoints) {
                $loyaltyPoints->total_points += $achievementData['points'];
                $loyaltyPoints->available_points += $achievementData['points'];
                $loyaltyPoints->level = $this->calculateLevelFromPoints($loyaltyPoints->total_points);
                $loyaltyPoints->save();

                PointsActivity::create([
                    'patient_id' => $patient->id,
                    'achievement_id' => $achievement->id,
                    'points' => $achievementData['points'],
                    'activity_type' => 'achievement',
                    'description' => 'Badge débloqué : ' . $achievementData['title'],
                ]);
            }
        }
    }

    /**
     * Award points for milestone completion
     */
    private function awardMilestonePoints($patient, $milestone)
    {
        $pointsEarned = 25; // Base points for milestone

        $loyaltyPoints = LoyaltyPoints::where('patient_id', $patient->id)->first();
        if ($loyaltyPoints) {
            $loyaltyPoints->total_points += $pointsEarned;
            $loyaltyPoints->available_points += $pointsEarned;
            $loyaltyPoints->level = $this->calculateLevelFromPoints($loyaltyPoints->total_points);
            $loyaltyPoints->save();

            PointsActivity::create([
                'patient_id' => $patient->id,
                'milestone_id' => $milestone->id,
                'points' => $pointsEarned,
                'activity_type' => 'milestone',
                'description' => 'Objectif atteint : ' . $milestone->title,
            ]);
        }
    }

    /**
     * Check goal completion achievement
     */
    private function checkGoalCompletionAchievement($patient)
    {
        $completedGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->count();

        if ($completedGoals == 1) {
            $this->unlockAchievement($patient, 'first_goal_completed');
        } elseif ($completedGoals == 5) {
            $this->unlockAchievement($patient, 'goal_getter_5');
        } elseif ($completedGoals == 10) {
            $this->unlockAchievement($patient, 'goal_champion_10');
        }
    }

    /**
     * Calculate level from points
     */
    private function calculateLevelFromPoints($points)
    {
        $levels = [
            0 => 1,   // 0-999 points = Level 1
            1000 => 2, // 1000-2499 points = Level 2
            2500 => 3, // 2500-4999 points = Level 3
            5000 => 4, // 5000-9999 points = Level 4
            10000 => 5, // 10000+ points = Level 5
        ];

        $level = 1;
        foreach ($levels as $threshold => $lvl) {
            if ($points >= $threshold) {
                $level = $lvl;
            }
        }

        return $level;
    }

    /**
     * Get level information
     */
    private function getLevelInfo($points)
    {
        $levelNames = [
            1 => 'Bronze',
            2 => 'Silver',
            3 => 'Gold',
            4 => 'Platinum',
            5 => 'Diamond',
        ];

        $levelThresholds = [
            1 => 0,
            2 => 1000,
            3 => 2500,
            4 => 5000,
            5 => 10000,
        ];

        $currentLevel = $this->calculateLevelFromPoints($points);
        $nextLevel = min(5, $currentLevel + 1);

        $pointsToNext = $nextLevel <= 5
            ? $levelThresholds[$nextLevel] - $points
            : 0;

        return [
            'level' => $levelNames[$currentLevel] ?? 'Bronze',
            'level_number' => $currentLevel,
            'next_level' => $levelNames[$nextLevel] ?? 'Max',
            'points_to_next' => max(0, $pointsToNext),
        ];
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
        } else {
            // Check yesterday
            $yesterday = $today->copy()->subDay();
            $hasSessionYesterday = ExerciseSession::where('patient_id', $patient->id)
                ->where('status', 'completed')
                ->whereDate('session_date', $yesterday)
                ->exists();

            if ($hasSessionYesterday) {
                $streak = 1;
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

    private function getProgramProgress($patient)
    {
        $programs = PatientProgramAssignment::with(['program.exercises'])
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->get();

        return $programs->map(function ($program) use ($patient) {
            $totalExercises = $program->program->exercises->count();

            $completedSessions = ExerciseSession::where('patient_id', $patient->id)
                ->where('program_assignment_id', $program->id)
                ->where('status', 'completed')
                ->count();

            // FIXED: Progress should never exceed 100%
            $progress = $totalExercises > 0
                ? round(min(($completedSessions / $totalExercises) * 100, 100), 1)
                : 0;

            return [
                'program_id' => $program->id,
                'program_name' => $program->program->name,
                'progress' => $progress,
                'completed_exercises' => $completedSessions,
                'total_exercises' => $totalExercises,
            ];
        })->values();
    }

    private function getPainTrends($patient)
    {
        $last7Days = Carbon::now()->subDays(6)->startOfDay(); // 7 days including today
        $today = Carbon::now()->endOfDay();

        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->whereNotNull('pain_level')
            ->whereBetween('session_date', [$last7Days->toDateString(), $today->toDateString()])
            ->select(
                DB::raw('DATE(session_date) as date'),
                DB::raw('COALESCE(AVG(pain_level), 0) as avg_pain'),
                DB::raw('COUNT(*) as session_count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trends = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');
            $session = $sessions->get($dateString);

            $trends[] = [
                'date' => $dateString,
                'avg_pain' => $session ? round($session->avg_pain, 1) : null,
                'session_count' => $session ? $session->session_count : 0,
            ];
        }

        // Calculate weekly average pain (only from days with data)
        $painValues = collect($trends)->pluck('avg_pain')->filter()->values();
        $weeklyAvgPain = $painValues->isNotEmpty() ? round($painValues->avg(), 1) : 0;

        return [
            'daily_trends' => $trends,
            'weekly_avg_pain' => $weeklyAvgPain,
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

        return $initialPain - $currentPain;
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

        return $recentScore - $previousScore;
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

        return $recentScore - $previousScore;
    }

    private function calculateWeeklyTarget($patient, $weekStart, $weekEnd)
    {
        $sessionGoals = PatientGoal::where('patient_id', $patient->id)
            ->where('metric_type', 'session_count')
            ->where('status', 'in-progress')
            ->get();

        $target = 0;
        foreach ($sessionGoals as $goal) {
            if ($goal->unit === 'sessions/year') {
                $target += round($goal->target_value / 52);
            } elseif ($goal->unit === 'sessions/month') {
                $target += round($goal->target_value / 4);
            } else {
                $target += $goal->target_value;
            }
        }

        return max(3, $target);
    }

    private function getFrequencyText($assignment)
    {
        // This is a simplified version - you might want to store frequency in the assignment
        return $assignment->program->frequency ?? '1x/jour';
    }

    private function formatNextSession($session)
    {
        $date = Carbon::parse($session->session_date);

        if ($date->isToday()) {
            return "Aujourd'hui " . Carbon::parse($session->session_time)->format('H:i');
        } elseif ($date->isTomorrow()) {
            return "Demain " . Carbon::parse($session->session_time)->format('H:i');
        } else {
            return $date->format('d/m') . " " . Carbon::parse($session->session_time)->format('H:i');
        }
    }

    private function getAppointmentTypeLabel($type)
    {
        $labels = [
            'consultation' => 'Consultation',
            'follow_up' => 'Suivi',
            'emergency' => 'Urgence',
            'initial_evaluation' => 'Première évaluation',
            'rehabilitation' => 'Rééducation',
        ];

        return $labels[$type] ?? $type;
    }

    /**
     * Get motivational quotes for the patient
     */
    public function getQuotes(Request $request)
    {
        $patient = Auth::user();

        $quotes = Quote::whereHas('patients', function ($query) use ($patient) {
            $query->where('kine_quotes.patient_id', $patient->id)
                ->where('kine_quotes.is_active', true);
        })
        ->with(['patients' => function ($query) use ($patient) {
            $query->where('kine_quotes.patient_id', $patient->id);
        }])
        ->active()
        ->ordered()
        ->get();

        if ($quotes->isEmpty()) {
            $quotes = Quote::whereDoesntHave('patients')
                ->active()
                ->ordered()
                ->get();
        }

        if ($quotes->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $formatted = $quotes->map(function ($quote) use ($patient) {

            $pivot = $quote->patients->first()?->pivot;

            $kine = $pivot
                ? User::find($pivot->kine_id)
                : null;

            return [
                'id' => $quote->id,
                'content' => $quote->content,
                'author' => $quote->author ?? ($kine?->fullName()),
                'author_title' => $quote->author_title ?? ($kine?->kineProfile->specialty ?? 'Kinésithérapeute'),
                'kine_id' => $kine?->id,
                'kine_name' => $kine?->fullName(),
                'kine_avatar' => $kine?->avatar_url,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'has_multiple' => $formatted->count() > 1,
        ]);
    }


    /**
     * Get emergency contacts for the patient
     */
    public function getEmergencyContacts(Request $request)
    {
        $patient = Auth::user();

        $assignedKines = $patient->assignedKines()
            ->with('kineProfile')
            ->get();

        $emergencyContacts = [];

        foreach ($assignedKines as $kine) {
            $emergencyPhone = $kine->kineProfile->emergency_phone ?? $kine->phone;

            if ($emergencyPhone && $kine->kineProfile->is_emergency_contact_available) {
                $emergencyContacts[] = [
                    'id' => $kine->id,
                    'name' => $kine->fullName(),
                    'specialty' => $kine->kineProfile->specialty ?? 'Kinésithérapeute',
                    'phone' => $emergencyPhone,
                    'avatar' => $kine->avatar_url,
                    'is_primary' => count($emergencyContacts) === 0,
                ];
            }
        }

        if (empty($emergencyContacts)) {
            $emergencyContacts[] = [
                'id' => 'default',
                'name' => 'Service d\'Urgence',
                'specialty' => 'SAMU',
                'phone' => '15',
                'avatar' => null,
                'is_primary' => true,
            ];

            $emergencyContacts[] = [
                'id' => 'default2',
                'name' => 'Urgence Médicale',
                'specialty' => 'Permanence',
                'phone' => '112',
                'avatar' => null,
                'is_primary' => false,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $emergencyContacts,
            'has_multiple' => count($emergencyContacts) > 1,
        ]);
    }

    public function getPainReports(Request $request)
    {
        $patient = Auth::user();

        $query = PainReport::where('patient_id', $patient->id)
            ->with(['kine:id,first_name,last_name,avatar_url'])
            ->orderBy('created_at', 'desc');

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Filter by pain level
        if ($request->has('min_pain')) {
            $query->where('pain_level', '>=', $request->min_pain);
        }

        if ($request->has('max_pain')) {
            $query->where('pain_level', '<=', $request->max_pain);
        }

        // Filter by location
        if ($request->has('location')) {
            $query->where('location', $request->location);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $reports = $query->paginate($perPage);

        $data = $reports->map(function ($report) {
            return [
                'id' => $report->id,
                'pain_level' => $report->pain_level,
                'pain_label' => $report->pain_level_label,
                'location' => $report->location,
                'location_label' => $report->location_label,
                'description' => $report->description,
                'trigger' => $report->trigger,
                'duration' => $report->duration,
                'medications' => $report->medications,
                'relieving_factors' => $report->relieving_factors,
                'worsening_factors' => $report->worsening_factors,
                'affects_sleep' => $report->affects_sleep,
                'affects_daily_activities' => $report->affects_daily_activities,
                'status' => $report->status,
                'is_urgent' => $report->isUrgent(),
                'created_at' => $report->created_at->toISOString(),
                'kine' => $report->kine ? [
                    'id' => $report->kine->id,
                    'name' => $report->kine->fullName(),
                    'avatar' => $report->kine->avatar_url,
                ] : null,
                'kine_notes' => $report->kine_notes,
                'reviewed_at' => $report->reviewed_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $reports->currentPage(),
                'from' => $reports->firstItem(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'to' => $reports->lastItem(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /**
     * Get pain statistics
     */
    public function getPainStatistics(Request $request)
    {
        $patient = Auth::user();
        $days = $request->get('days', 30);
        $startDate = Carbon::now()->subDays($days);

        $reports = PainReport::where('patient_id', $patient->id)
            ->where('created_at', '>=', $startDate)
            ->get();

        $statistics = [
            'total_reports' => $reports->count(),
            'average_pain' => round($reports->avg('pain_level'), 1),
            'min_pain' => $reports->min('pain_level'),
            'max_pain' => $reports->max('pain_level'),
            'most_common_location' => $reports->groupBy('location')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->first(),
            'pain_by_location' => $reports->groupBy('location')
                ->map(function ($locationReports) {
                    return [
                        'count' => $locationReports->count(),
                        'average' => round($locationReports->avg('pain_level'), 1),
                    ];
                }),
            'pain_by_day' => $reports->groupBy(function ($report) {
                    return $report->created_at->format('Y-m-d');
                })
                ->map(function ($dayReports) {
                    return [
                        'count' => $dayReports->count(),
                        'average' => round($dayReports->avg('pain_level'), 1),
                    ];
                }),
            'urgent_reports' => $reports->filter(function ($report) {
                return $report->isUrgent();
            })->count(),
            'affects_sleep_count' => $reports->where('affects_sleep', true)->count(),
            'affects_daily_activities_count' => $reports->where('affects_daily_activities', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }
}
