<?php

namespace App\Http\Controllers\Api\Kine;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\{
    User,
    Appointment,
    AppointmentSlot,
    Notification,
    PatientAnalytics,
    ExerciseSession,
    Exercise,
    ExerciseCategory,
    DailyCheckin,
    CheckIn,
    PatientProgramAssignment,
    Program,
    Invoice,
    PatientDocument,
    Message,
    Milestone,
    Achievement,
    PatientGoal,
    CancellationReason,
    KineProfile,
    PatientProfile,
    Product,
    ProductRecommendation,
    ProgressReport,
    PointsTransaction,
    RedeemedReward,
    CartItem,
    LoyaltyPoints,
    Subscription,
    Order
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get current authenticated kine ID
     */
    private function getKineId(): string
    {
        return Auth::id();
    }

    /* ============================================
       MAIN DASHBOARD OVERVIEW
       ============================================ */

    /**
     * Get dashboard overview data
     */
    public function getDashboardOverview(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $filterType = $request->input('filter_type', 'today');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $data = $this->calculateDashboardOverview($kineId, $filterType, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filter' => [
                    'type' => $filterType,
                    'start_date' => $data['date_range']['start'] ?? null,
                    'end_date' => $data['date_range']['end'] ?? null,
                    'label' => $this->getFilterLabel($filterType, $startDate, $endDate)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard overview error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Calculate dashboard overview metrics
     */
    private function calculateDashboardOverview(string $kineId, string $filterType = 'today', ?string $startDate = null, ?string $endDate = null): array
    {
        $now = Carbon::now();

        $dateRange = $this->getDateRange($filterType, $startDate, $endDate);
        $startDateObj = $dateRange['start'];
        $endDateObj = $dateRange['end'];

        $daysDiff = $startDateObj->diffInDays($endDateObj);
        $dateRangeInfo = [
            'start' => $startDateObj->toDateString(),
            'end' => $endDateObj->toDateString(),
            'days' => (int) ($daysDiff + 1)
        ];

        $appointments = Appointment::forKine($kineId)
            ->betweenDates($startDateObj, $endDateObj)
            ->with(['patient:id,first_name,last_name', 'slot:id,start_time,end_time,kine_id'])
            ->orderBySlotTime('asc')
            ->get();

        $todaysAppointments = Appointment::forKine($kineId)
            ->whereHas('slot', function ($query) {
                $query->whereDate('start_time', today());
            })
            ->with(['patient:id,first_name,last_name', 'slot:id,start_time,end_time'])
            ->orderBySlotTime('asc')
            ->get()
            ->map(function ($appointment) {
                return [
                    'id' => $appointment->id,
                    'patient_name' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                    'time' => $appointment->slot->start_time->format('H:i'),
                    'duration' => $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time),
                    'status' => $appointment->status,
                    'type' => $appointment->type,
                    'color' => $appointment->color,
                ];
            })
            ->values();

        $completedAppointments = $appointments->where('status', AppointmentStatus::COMPLETED->value);

        $stats = [
            'appointments' => (int) $appointments->count(),
            'completed_appointments' => (int) $completedAppointments->count(),
            'completed_exercises' => (int) ExerciseSession::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('session_date', [$startDateObj, $endDateObj])
                ->where('status', 'completed')
                ->count(),
            'new_messages' => (int) Message::where('receiver_id', $kineId)
                ->where('is_read', false)
                ->whereBetween('created_at', [$startDateObj, $endDateObj])
                ->count(),
            // Calculate revenue from completed appointments
            'revenue' => (float) $completedAppointments->sum('price'),
        ];

        // 3. Recent patients (active in the date range)
        $recentPatients = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->where('is_active', true)
            ->whereHas('appointments', function ($query) use ($startDateObj) {
                $query->where('status', AppointmentStatus::COMPLETED->value)
                    ->where('created_at', '>=', $startDateObj);
            })
            ->with(['patientProfile'])
            ->limit(6)
            ->get()
            ->map(function ($patient) use ($startDateObj, $endDateObj) {
                $latestAnalytics = PatientAnalytics::where('patient_id', $patient->id)
                    ->whereBetween('created_at', [$startDateObj, $endDateObj])
                    ->latest()
                    ->first();

                return [
                    'id' => $patient->id,
                    'name' => $patient->first_name . ' ' . $patient->last_name,
                    'avatar' => $patient->avatar_url,
                    'last_session' => $patient->appointments()->latest()->first()?->created_at?->format('Y-m-d'),
                    'adherence_rate' => $latestAnalytics?->adherence_rate ? (float) $latestAnalytics->adherence_rate : 0,
                    'current_streak' => $latestAnalytics?->streak_current ? (int) $latestAnalytics->streak_current : 0,
                ];
            });

        // 4. Quick stats (comparing with previous period)
        $previousRange = $this->getPreviousDateRange($filterType, $startDateObj, $endDateObj);

        // Calculate previous period patient count for growth comparison
        $previousPatientsCount = $this->getPreviousPeriodPatients($kineId, $filterType, $startDateObj, $endDateObj);
        $currentPatientsCount = (int) User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->where('is_active', true)
            ->count();

        $patientGrowth = $previousPatientsCount > 0
            ? (($currentPatientsCount - $previousPatientsCount) / $previousPatientsCount) * 100
            : ($currentPatientsCount > 0 ? 100 : 0);

        // Calculate previous period revenue from completed appointments
        $previousPeriodRevenue = (float) Appointment::forKine($kineId)
            ->betweenDates($previousRange['start'], $previousRange['end'])
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->get()
            ->sum('price');

        $quickStats = [
            'total_patients' => $currentPatientsCount,
            'patient_growth' => round($patientGrowth, 1),
            'revenue' => (float) $stats['revenue'],
            'revenue_previous' => $previousPeriodRevenue,
            'avg_adherence' => (float) PatientAnalytics::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [$startDateObj, $endDateObj])
                ->avg('adherence_rate') ?? 0,
            'avg_adherence_previous' => (float) $this->getPreviousPeriodAdherence($kineId, $filterType, $startDateObj, $endDateObj),
            'satisfaction_rate' => (float) $this->calculateSatisfactionRate($kineId, $startDateObj, $endDateObj),
            'satisfaction_rate_previous' => (float) $this->getPreviousPeriodSatisfaction($kineId, $filterType, $startDateObj, $endDateObj),
        ];

        // 5. Notifications (no date filtering needed)
        $notifications = Notification::where('user_id', $kineId)
            ->where('is_read', false)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'time_ago' => $notification->created_at->diffForHumans(),
                    'action_url' => $notification->action_url,
                    'priority' => $notification->priority,
                ];
            });

        // 6. Upcoming milestones
        $upcomingMilestones = Milestone::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->where('achieved', false)
            ->with(['patient:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($milestone) {
                $progress = $milestone->target_value > 0
                    ? round(($milestone->current_value / $milestone->target_value) * 100, 1)
                    : 0;

                return [
                    'patient_name' => $milestone->patient->first_name . ' ' . $milestone->patient->last_name,
                    'title' => $milestone->title,
                    'description' => $milestone->description,
                    'progress' => (float) $progress,
                    'type' => $milestone->type,
                    'icon' => $milestone->icon,
                ];
            });

        // 7. Recent exercises in the date range
        $recentExercises = ExerciseSession::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->whereBetween('session_date', [$startDateObj, $endDateObj])
            ->where('status', 'completed')
            ->with(['patient:id,first_name,last_name', 'exercise:id,name'])
            ->orderBy('session_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($session) {
                return [
                    'patient_name' => $session->patient->first_name . ' ' . $session->patient->last_name,
                    'exercise_name' => $session->exercise->name ?? 'Unknown Exercise',
                    'date' => $session->session_date->format('Y-m-d'),
                    'pain_level' => $session->pain_level ? (int) $session->pain_level : 0,
                    'duration' => $session->duration_minutes ? (int) $session->duration_minutes : 0,
                ];
            });

        return [
            'todays_appointments' => $todaysAppointments,
            'stats' => $stats,
            'recent_patients' => $recentPatients,
            'quick_stats' => $quickStats,
            'notifications' => $notifications,
            'upcoming_milestones' => $upcomingMilestones,
            'recent_exercises' => $recentExercises,
            'date_range' => $dateRangeInfo,
            'last_updated' => $now->toISOString(),
        ];
    }

    /**
     * Get date range based on filter type
     */
    private function getDateRange(string $filterType, ?string $startDate = null, ?string $endDate = null): array
    {
        $now = Carbon::now();

        switch ($filterType) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;

            case 'last_7_days':
                $start = $now->copy()->subDays(7)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case 'last_30_days':
                $start = $now->copy()->subDays(30)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;

            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $now->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;

            case 'custom':
                $start = $startDate ? Carbon::parse($startDate)->startOfDay() : $now->copy()->startOfDay();
                $end = $endDate ? Carbon::parse($endDate)->endOfDay() : $now->copy()->endOfDay();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    /**
     * Get previous period date range for comparison
     */
    private function getPreviousDateRange(string $filterType, Carbon $currentStart, Carbon $currentEnd): array
    {
        $daysDiff = $currentStart->diffInDays($currentEnd);

        switch ($filterType) {
            case 'today':
                $start = $currentStart->copy()->subDay();
                $end = $currentEnd->copy()->subDay();
                break;

            case 'yesterday':
                $start = $currentStart->copy()->subDay();
                $end = $currentEnd->copy()->subDay();
                break;

            case 'last_7_days':
                $start = $currentStart->copy()->subDays(7);
                $end = $currentEnd->copy()->subDays(7);
                break;

            case 'last_30_days':
                $start = $currentStart->copy()->subDays(30);
                $end = $currentEnd->copy()->subDays(30);
                break;

            case 'this_month':
                $start = $currentStart->copy()->subMonth()->startOfMonth();
                $end = $currentStart->copy()->subMonth()->endOfMonth();
                break;

            case 'last_month':
                $start = $currentStart->copy()->subMonths(2)->startOfMonth();
                $end = $currentStart->copy()->subMonths(2)->endOfMonth();
                break;

            default:
                $start = $currentStart->copy()->subDays($daysDiff + 1);
                $end = $currentEnd->copy()->subDays($daysDiff + 1);
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    /**
     * Get previous period patient count for comparison
     */
    private function getPreviousPeriodPatients(string $kineId, string $filterType, Carbon $currentStart, Carbon $currentEnd): int
    {
        $previousRange = $this->getPreviousDateRange($filterType, $currentStart, $currentEnd);

        return (int) User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->where('is_active', true)
            ->whereHas('appointments', function ($query) use ($previousRange) {
                $query->where('status', 'completed')
                    ->whereBetween('created_at', [$previousRange['start'], $previousRange['end']]);
            })
            ->count();
    }

    /**
     * Get previous period revenue for comparison
     */
    private function getPreviousPeriodRevenue(string $kineId, string $filterType, Carbon $currentStart, Carbon $currentEnd): float
    {
        $previousRange = $this->getPreviousDateRange($filterType, $currentStart, $currentEnd);

        return (float) Appointment::forKine($kineId)
            ->betweenDates($previousRange['start'], $previousRange['end'])
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->get()
            ->sum('price');
    }

    /**
     * Get previous period adherence rate for comparison
     */
    private function getPreviousPeriodAdherence(string $kineId, string $filterType, Carbon $currentStart, Carbon $currentEnd): float
    {
        $previousRange = $this->getPreviousDateRange($filterType, $currentStart, $currentEnd);

        return PatientAnalytics::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->whereBetween('created_at', [$previousRange['start'], $previousRange['end']])
            ->avg('adherence_rate') ?? 0;
    }

    /**
     * Get previous period satisfaction rate for comparison
     */
    private function getPreviousPeriodSatisfaction(string $kineId, string $filterType, Carbon $currentStart, Carbon $currentEnd): float
    {
        $previousRange = $this->getPreviousDateRange($filterType, $currentStart, $currentEnd);

        return $this->calculateSatisfactionRate($kineId, $previousRange['start'], $previousRange['end']);
    }


    /**
     * Calculate satisfaction rate
     */
    private function calculateSatisfactionRate(string $kineId, Carbon $startDate, Carbon $endDate): float
    {
        // Appointment completion rate
        $totalAppointments = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                $query->where('kine_id', $kineId)
                    ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->count();

        $completedAppointments = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                $query->where('kine_id', $kineId)
                    ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'completed')
            ->count();

        $appointmentRate = $totalAppointments > 0 ? ($completedAppointments / $totalAppointments) * 100 : 100;

        // Exercise adherence rate
        $adherenceRate = PatientAnalytics::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->avg('adherence_rate') ?? 80;

        // Combined satisfaction rate (weighted average)
        $satisfaction = ($appointmentRate * 0.6) + ($adherenceRate * 0.4);
        return min(100, round($satisfaction, 1));
    }

    /**
     * Get filter label for display
     */
    private function getFilterLabel(string $filterType, ?string $startDate = null, ?string $endDate = null): string
    {
        switch ($filterType) {
            case 'today':
                return "Aujourd'hui";
            case 'yesterday':
                return 'Hier';
            case 'last_7_days':
                return '7 derniers jours';
            case 'last_30_days':
                return '30 derniers jours';
            case 'this_month':
                return 'Ce mois-ci';
            case 'last_month':
                return 'Mois dernier';
            case 'this_year':
                return 'Cette année';
            case 'custom':
                if ($startDate && $endDate) {
                    $start = Carbon::parse($startDate);
                    $end = Carbon::parse($endDate);

                    if ($start->isSameDay($end)) {
                        return $start->format('d/m/Y');
                    } else {
                        return $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
                    }
                }
                return 'Personnalisé';
            default:
                return "Aujourd'hui";
        }
    }
    /* ============================================
       CSV IMPORT FUNCTIONALITY
       ============================================ */

    /**
     * Import patients from CSV
     */
    public function importPatients(Request $request)
    {
        Log::info('Starting CSV import', [
            'kine_id' => $this->getKineId(),
            'import_type' => $request->input('import_type'),
            'file_name' => $request->file('csv_file') ? $request->file('csv_file')->getClientOriginalName() : null,
        ]);
        try {
            $kineId = $this->getKineId();

            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:2048',
                'import_type' => 'required|in:patients,appointments,exercises',
            ]);


            $file = $request->file('csv_file');
            $importType = $request->input('import_type');

            // Parse CSV file
            $csvData = $this->parseCSV($file);
            Log::info('CSV file parsed', [
                'kine_id' => $kineId,
                'import_type' => $importType,
                'total_records' => count($csvData),
                'data' => array_slice($csvData, 0, 5)
            ]);
            if (empty($csvData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV file is empty or invalid'
                ], 400);
            }

            $results = [];
            $errors = [];
            $successCount = 0;

            switch ($importType) {
                case 'patients':
                    $results = $this->importPatientsFromCSV($csvData, $kineId, $errors, $successCount);
                    break;

                case 'appointments':
                    $results = $this->importAppointmentsFromCSV($csvData, $kineId, $errors, $successCount);
                    break;

                case 'exercises':
                    $results = $this->importExercisesFromCSV($csvData, $kineId, $errors, $successCount);
                    break;
            }

            // Log the import
            Log::info('CSV import completed', [
                'kine_id' => $kineId,
                'import_type' => $importType,
                'file_name' => $file->getClientOriginalName(),
                'total_records' => count($csvData),
                'successful_records' => $successCount,
                'failed_records' => count($errors),
                'errors' => $errors
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Import completed successfully',
                'data' => [
                    'total_records' => count($csvData),
                    'successful' => $successCount,
                    'failed' => count($errors),
                    'errors' => $errors,
                    'sample_csv' => $this->getSampleCSV($importType),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('CSV import error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId(),
                'import_type' => $request->input('import_type')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to import CSV',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Parse CSV file
     */
    private function parseCSV($file): array
    {
        $csvData = [];
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        // Read headers
        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);
            return [];
        }

        // Clean headers (remove BOM and trim)
        $headers = array_map(function($header) {
            // Remove UTF-8 BOM if present
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            return trim($header);
        }, $headers);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                // Trim all values
                $row = array_map('trim', $row);
                $csvData[] = array_combine($headers, $row);
            }
        }

        fclose($handle);
        return $csvData;
    }

    /**
     * Import patients from CSV data
     */
    private function importPatientsFromCSV(array $csvData, string $kineId, array &$errors, int &$successCount): array
    {
        $results = [];

        foreach ($csvData as $index => $row) {
            try {
                $rowNumber = $index + 2;

                $requiredFields = ['email', 'first_name', 'last_name'];
                foreach ($requiredFields as $field) {
                    if (empty($row[$field] ?? null)) {
                        $errors[] = "Row {$rowNumber}: Missing required field '{$field}'";
                        continue 2;
                    }
                }

                if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row {$rowNumber}: Invalid email format '{$row['email']}'";
                    continue;
                }

                $existingUser = User::where('email', $row['email'])->first();

                if ($existingUser) {
                    $isAssigned = DB::table('kine_patient_assignments')
                        ->where('kine_id', $kineId)
                        ->where('patient_id', $existingUser->id)
                        ->exists();

                    if ($isAssigned) {
                        $errors[] = "Row {$rowNumber}: Patient already assigned to you";
                        continue;
                    }

                    DB::table('kine_patient_assignments')->insert([
                        'id' => Str::uuid(),
                        'kine_id' => $kineId,
                        'patient_id' => $existingUser->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $userId = $existingUser->id;
                } else {
                    $password = Str::random(12);

                    $user = User::create([
                        'email' => $row['email'],
                        'password' => Hash::make($password),
                        'role' => 'patient',
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'phone' => $row['phone'] ?? null,
                        'date_of_birth' => !empty($row['date_of_birth']) ? Carbon::parse($row['date_of_birth']) : null,
                        'address' => $row['address'] ?? null,
                        'city' => $row['city'] ?? null,
                        'postal_code' => $row['postal_code'] ?? null,
                        'country' => $row['country'] ?? 'France',
                        'is_active' => true,
                    ]);

                    $userId = $user->id;

                    PatientProfile::create([
                        'user_id' => $userId,
                        'birth_date' => !empty($row['date_of_birth']) ? Carbon::parse($row['date_of_birth']) : null,
                        'gender' => $row['gender'] ?? null,
                        'height_cm' => isset($row['height_cm']) ? (int) $row['height_cm'] : null,
                        'weight_kg' => isset($row['weight_kg']) ? (float) $row['weight_kg'] : null,
                        'medical_notes' => $row['medical_notes'] ?? null,
                        'preferred_language' => $row['preferred_language'] ?? 'fr',
                    ]);

                    DB::table('kine_patient_assignments')->insert([
                        'id' => Str::uuid(),
                        'kine_id' => $kineId,
                        'patient_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $successCount++;
                $results[] = [
                    'row' => $rowNumber,
                    'email' => $row['email'],
                    'name' => $row['first_name'] . ' ' . $row['last_name'],
                    'status' => 'success',
                    'action' => isset($existingUser) ? 'assigned' : 'created',
                ];

            } catch (\Exception $e) {
                $errors[] = "Row {$rowNumber}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Import appointments from CSV
     */
    private function importAppointmentsFromCSV(array $csvData, string $kineId, array &$errors, int &$successCount): array
    {
        $results = [];

        foreach ($csvData as $index => $row) {
            try {
                $rowNumber = $index + 2;

                // Validate required fields
                $requiredFields = ['patient_email', 'start_date', 'start_time', 'type'];
                foreach ($requiredFields as $field) {
                    if (empty($row[$field] ?? null)) {
                        $errors[] = "Row {$rowNumber}: Missing required field '{$field}'";
                        continue 2;
                    }
                }

                // Find patient
                $patient = User::where('email', $row['patient_email'])
                    ->where('role', 'patient')
                    ->first();

                if (!$patient) {
                    $errors[] = "Row {$rowNumber}: Patient not found with email '{$row['patient_email']}'";
                    continue;
                }

                // Check if patient is assigned to this kine
                $isAssigned = DB::table('kine_patient_assignments')
                    ->where('kine_id', $kineId)
                    ->where('patient_id', $patient->id)
                    ->exists();

                if (!$isAssigned) {
                    $errors[] = "Row {$rowNumber}: Patient '{$row['patient_email']}' is not assigned to you";
                    continue;
                }

                // Parse date and time
                try {
                    $startDateTime = Carbon::parse($row['start_date'] . ' ' . $row['start_time']);
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: Invalid date/time format. Use YYYY-MM-DD and HH:MM";
                    continue;
                }

                // Default duration is 45 minutes
                $duration = isset($row['duration']) ? (int) $row['duration'] : 45;
                $endDateTime = $startDateTime->copy()->addMinutes($duration);

                // Create appointment slot
                $slot = AppointmentSlot::create([
                    'kine_id' => $kineId,
                    'start_time' => $startDateTime,
                    'end_time' => $endDateTime,
                    'is_available' => false,
                ]);

                // Create appointment
                $appointment = Appointment::create([
                    'patient_id' => $patient->id,
                    'slot_id' => $slot->id,
                    'type' => $row['type'],
                    'notes' => $row['notes'] ?? null,
                    'status' => $row['status'] ?? 'scheduled',
                    'price' => isset($row['price']) ? (float) $row['price'] : 0,
                ]);

                $successCount++;
                $results[] = [
                    'row' => $rowNumber,
                    'patient' => $patient->first_name . ' ' . $patient->last_name,
                    'date' => $startDateTime->format('Y-m-d'),
                    'time' => $startDateTime->format('H:i'),
                    'duration' => $duration,
                    'type' => $row['type'],
                    'status' => 'success',
                ];

            } catch (\Exception $e) {
                $errors[] = "Row {$rowNumber}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Import exercises from CSV data
     */
    private function importExercisesFromCSV(array $csvData, string $kineId, array &$errors, int &$successCount): array
    {
        $results = [];

        foreach ($csvData as $index => $row) {
            try {
                $rowNumber = $index + 2;

                // Validate required fields
                $requiredFields = ['patient_email', 'exercise_name', 'session_date'];
                foreach ($requiredFields as $field) {
                    if (empty($row[$field] ?? null)) {
                        $errors[] = "Row {$rowNumber}: Missing required field '{$field}'";
                        continue 2;
                    }
                }

                // Find patient
                $patient = User::where('email', $row['patient_email'])
                    ->where('role', 'patient')
                    ->first();

                if (!$patient) {
                    $errors[] = "Row {$rowNumber}: Patient not found with email '{$row['patient_email']}'";
                    continue;
                }

                // Check if patient is assigned to this kine
                $isAssigned = DB::table('kine_patient_assignments')
                    ->where('kine_id', $kineId)
                    ->where('patient_id', $patient->id)
                    ->exists();

                if (!$isAssigned) {
                    $errors[] = "Row {$rowNumber}: Patient '{$row['patient_email']}' is not assigned to you";
                    continue;
                }

                // Find or create exercise
                $exercise = Exercise::firstOrCreate(
                    ['name' => $row['exercise_name'], 'kine_id' => $kineId],
                    [
                        'description' => $row['description'] ?? null,
                        'duration_seconds' => isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : 600,
                        'sets' => $row['sets'] ?? 3,
                        'reps' => $row['reps'] ?? 10,
                        'difficulty' => $row['difficulty'] ?? 'beginner',
                        'is_active' => true,
                    ]
                );

                // Parse session date
                try {
                    $sessionDate = Carbon::parse($row['session_date']);
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: Invalid date format. Use YYYY-MM-DD";
                    continue;
                }

                // Create exercise session
                $session = ExerciseSession::create([
                    'patient_id' => $patient->id,
                    'exercise_id' => $exercise->id,
                    'session_date' => $sessionDate,
                    'pain_level' => isset($row['pain_level']) ? (int) $row['pain_level'] : null,
                    'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : 10,
                    'notes' => $row['notes'] ?? null,
                    'status' => $row['status'] ?? 'completed',
                    'actual_repetitions' => isset($row['actual_repetitions']) ? (int) $row['actual_repetitions'] : null,
                ]);

                $successCount++;
                $results[] = [
                    'row' => $rowNumber,
                    'patient' => $patient->first_name . ' ' . $patient->last_name,
                    'exercise' => $exercise->name,
                    'date' => $sessionDate->format('Y-m-d'),
                    'status' => 'success',
                ];

            } catch (\Exception $e) {
                $errors[] = "Row {$rowNumber}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get sample CSV format for download
     */
    public function getSampleCSVFormat(Request $request)
    {
        try {
            $importType = $request->input('type', 'patients');

            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $importType,
                    'sample' => $this->getSampleCSV($importType),
                    'instructions' => $this->getImportInstructions($importType),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Sample CSV error', [
                'error' => $e->getMessage(),
                'type' => $request->input('type')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get sample CSV format'
            ], 500);
        }
    }

    /**
     * Get sample CSV data
     */
    private function getSampleCSV(string $type): array
    {
        switch ($type) {
            case 'patients':
                return [
                    'headers' => ['email', 'first_name', 'last_name', 'phone', 'date_of_birth', 'address', 'city', 'postal_code', 'country', 'gender', 'height_cm', 'weight_kg', 'medical_notes', 'preferred_language'],
                    'sample_rows' => [
                        ['patient1@example.com', 'Marie', 'Leroy', '+33123456789', '1980-05-15', '123 Rue de Paris', 'Paris', '75001', 'France', 'female', '165', '65.5', 'Lombalgie chronique', 'fr'],
                        ['patient2@example.com', 'Jean', 'Martin', '+33198765432', '1975-11-22', '456 Avenue des Champs', 'Lyon', '69001', 'France', 'male', '178', '82.0', 'Rééducation post-AVC', 'fr'],
                    ]
                ];

            case 'appointments':
                return [
                    'headers' => ['patient_email', 'start_date', 'start_time', 'duration', 'type', 'notes', 'status', 'price'],
                    'sample_rows' => [
                        ['patient1@example.com', '2024-01-15', '09:00', '45', 'consultation', 'Première séance', 'scheduled', '50'],
                        ['patient2@example.com', '2024-01-15', '10:30', '60', 'follow_up', 'Suivi mensuel', 'scheduled', '60'],
                    ]
                ];

            case 'exercises':
                return [
                    'headers' => ['patient_email', 'exercise_name', 'session_date', 'pain_level', 'duration_minutes', 'notes', 'status', 'actual_repetitions'],
                    'sample_rows' => [
                        ['patient1@example.com', 'Étirements lombaires', '2024-01-10', '3', '15', 'Patient a bien effectué les exercices', 'completed', '30'],
                        ['patient2@example.com', 'Renforcement quadriceps', '2024-01-10', '2', '20', 'Progrès visible', 'completed', '40'],
                    ]
                ];

            default:
                return [];
        }
    }

    /**
     * Get import instructions
     */
    private function getImportInstructions(string $type): string
    {
        switch ($type) {
            case 'patients':
                return "Import patients from CSV. Required fields: email, first_name, last_name. If patient exists, they will be assigned to you. If not, a new patient will be created.";

            case 'appointments':
                return "Import appointments from CSV. Required fields: patient_email, start_date (YYYY-MM-DD), start_time (HH:MM), type. Patient must already be assigned to you.";

            case 'exercises':
                return "Import exercise sessions from CSV. Required fields: patient_email, exercise_name, session_date (YYYY-MM-DD). Patient must already be assigned to you.";

            default:
                return "Invalid import type.";
        }
    }

    /* ============================================
       QUICK ACTIONS
       ============================================ */

    /**
     * Perform quick action
     */
    public function quickAction(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $action = $request->input('action');
            $data = $request->except('action');

            $result = null;
            $message = '';

            switch ($action) {
                case 'add_quick_note':
                    $result = $this->addQuickNote($kineId, $data);
                    $message = 'Note added successfully';
                    break;

                case 'send_reminder':
                    $result = $this->sendBulkReminder($kineId, $data);
                    $message = 'Reminders sent successfully';
                    break;

                case 'create_quick_appointment':
                    $result = $this->createQuickAppointment($kineId, $data);
                    $message = 'Appointment created successfully';
                    break;

                case 'generate_quick_report':
                    $result = $this->generateQuickReport($kineId, $data);
                    $message = 'Report generated successfully';
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid action'
                    ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Quick action error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId(),
                'action' => $request->input('action')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to perform action',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Add quick note to patient
     */
    private function addQuickNote(string $kineId, array $data)
    {
        $validator = Validator::make($data, [
            'patient_id' => 'required|exists:users,id',
            'note' => 'required|string|max:500',
            'type' => 'required|in:progress,reminder,observation',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        // Check if patient is assigned to kine
        $isAssigned = DB::table('kine_patient_assignments')
            ->where('kine_id', $kineId)
            ->where('patient_id', $data['patient_id'])
            ->exists();

        if (!$isAssigned) {
            throw new \Exception('Patient is not assigned to you');
        }

        // Create a patient document as a note
        $document = PatientDocument::create([
            'patient_id' => $data['patient_id'],
            'kine_id' => $kineId,
            'title' => 'Note: ' . ucfirst($data['type']),
            'description' => $data['note'],
            'type' => 'note',
            'file_path' => null,
            'is_important' => $data['type'] === 'reminder',
        ]);

        return [
            'document_id' => $document->id,
            'patient_id' => $data['patient_id'],
            'type' => $data['type'],
        ];
    }

    /**
     * Send bulk reminder to patients
     */
    private function sendBulkReminder(string $kineId, array $data)
    {
        $validator = Validator::make($data, [
            'patient_ids' => 'required|array',
            'patient_ids.*' => 'exists:users,id',
            'message' => 'required|string|max:500',
            'reminder_type' => 'required|in:appointment,exercise,payment',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        $sentCount = 0;

        foreach ($data['patient_ids'] as $patientId) {
            // Check if patient is assigned to kine
            $isAssigned = DB::table('kine_patient_assignments')
                ->where('kine_id', $kineId)
                ->where('patient_id', $patientId)
                ->exists();

            if (!$isAssigned) {
                continue;
            }

            // Create notification
            Notification::create([
                'user_id' => $patientId,
                'type' => 'reminder_' . $data['reminder_type'],
                'title' => 'Rappel: ' . ucfirst(str_replace('_', ' ', $data['reminder_type'])),
                'message' => $data['message'],
                'priority' => 'medium',
                'is_read' => false,
            ]);

            $sentCount++;
        }

        return ['sent_count' => $sentCount];
    }

    /**
     * Create quick appointment
     */
    private function createQuickAppointment(string $kineId, array $data)
    {
        $validator = Validator::make($data, [
            'patient_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:15|max:120',
            'type' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        // Check if patient is assigned to kine
        $isAssigned = DB::table('kine_patient_assignments')
            ->where('kine_id', $kineId)
            ->where('patient_id', $data['patient_id'])
            ->exists();

        if (!$isAssigned) {
            throw new \Exception('Patient is not assigned to you');
        }

        $startDateTime = Carbon::parse($data['date'] . ' ' . $data['time']);
        $endDateTime = $startDateTime->copy()->addMinutes($data['duration']);

        // Create slot
        $slot = AppointmentSlot::create([
            'kine_id' => $kineId,
            'start_time' => $startDateTime,
            'end_time' => $endDateTime,
            'is_available' => false,
        ]);

        // Create appointment
        $appointment = Appointment::create([
            'patient_id' => $data['patient_id'],
            'slot_id' => $slot->id,
            'type' => $data['type'],
            'status' => 'scheduled',
            'price' => $data['price'] ?? 0,
        ]);

        return [
            'appointment_id' => $appointment->id,
            'patient_id' => $data['patient_id'],
            'date' => $startDateTime->format('Y-m-d'),
            'time' => $startDateTime->format('H:i'),
            'duration' => $data['duration'],
            'type' => $data['type'],
        ];
    }

    /**
     * Generate quick report
     */
    private function generateQuickReport(string $kineId, array $data)
    {
        $validator = Validator::make($data, [
            'report_type' => 'required|in:daily,weekly,monthly',
            'format' => 'required|in:summary,detailed',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        $period = $data['report_type'];
        $now = Carbon::now();

        switch ($period) {
            case 'daily':
                $startDate = $now->copy()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                break;
            case 'weekly':
                $startDate = $now->copy()->startOfWeek();
                $endDate = $now->copy()->endOfWeek();
                break;
            case 'monthly':
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                break;
            default:
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
        }

        // Generate report data
        $report = [
            'period' => $period,
            'date_range' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'summary' => $this->generateReportSummary($kineId, $startDate, $endDate, $data['format']),
            'generated_at' => now()->toISOString(),
        ];

        return $report;
    }

    /**
     * Generate report summary
     */
    private function generateReportSummary(string $kineId, Carbon $startDate, Carbon $endDate, string $format): array
    {
        // Get completed appointments for revenue calculation
        $completedAppointments = Appointment::forKine($kineId)
            ->betweenDates($startDate, $endDate)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->get();

        $summary = [
            'appointments' => [
                'total' => Appointment::forKine($kineId)
                    ->betweenDates($startDate, $endDate)
                    ->count(),
                'completed' => $completedAppointments->count(),
                'cancelled' => Appointment::forKine($kineId)
                    ->betweenDates($startDate, $endDate)
                    ->where('status', AppointmentStatus::CANCELED->value)
                    ->count(),
            ],
            // Calculate revenue from completed appointments
            'revenue' => (float) $completedAppointments->sum('price'),
            'exercises' => [
                'total' => ExerciseSession::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                        $query->where('kine_id', $kineId);
                    })
                    ->whereBetween('session_date', [$startDate, $endDate])
                    ->count(),
                'completed' => ExerciseSession::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                        $query->where('kine_id', $kineId);
                    })
                    ->whereBetween('session_date', [$startDate, $endDate])
                    ->where('status', 'completed')
                    ->count(),
            ],
        ];

        if ($format === 'detailed') {
            $summary['patients'] = [
                'active' => User::where('role', 'patient')
                    ->whereHas('assignedKine', function ($query) use ($kineId) {
                        $query->where('kine_id', $kineId);
                    })
                    ->where('is_active', true)
                    ->count(),
                'new' => User::where('role', 'patient')
                    ->whereHas('assignedKine', function ($query) use ($kineId) {
                        $query->where('kine_id', $kineId);
                    })
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count(),
            ];

            // Get top exercises
            $topExercises = ExerciseSession::whereHas('patient.assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('session_date', [$startDate, $endDate])
                ->where('status', 'completed')
                ->with('exercise:id,name')
                ->select('exercise_id', DB::raw('COUNT(*) as count'))
                ->groupBy('exercise_id')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'exercise_name' => $item->exercise->name ?? 'Unknown',
                        'count' => $item->count,
                    ];
                });

            $summary['top_exercises'] = $topExercises;
        }

        return $summary;
    }

    /* ============================================
       NOTIFICATIONS MANAGEMENT
       ============================================ */

    /**
     * Get notifications
     */
    public function getNotifications(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $limit = $request->input('limit', 20);
            $unreadOnly = $request->boolean('unread_only', false);
            Log::info('Fetching notifications', [
                'kine_id' => $kineId,
                'limit' => $limit,
                'unread_only' => $unreadOnly
            ]);
            $query = Notification::where('user_id', $kineId)
                ->orderBy('created_at', 'desc');

            if ($unreadOnly) {
                $query->where('is_read', false);
            }

            $notifications = $query->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $notifications,
                    'unread_count' => Notification::where('user_id', $kineId)
                        ->where('is_read', false)
                        ->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Get notifications error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications'
            ], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead(Request $request, string $notificationId)
    {
        try {
            $kineId = $this->getKineId();

            $notification = Notification::where('id', $notificationId)
                ->where('user_id', $kineId)
                ->firstOrFail();

            $notification->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Mark notification as read error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId(),
                'notification_id' => $notificationId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read'
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsAsRead(Request $request)
    {
        try {
            $kineId = $this->getKineId();

            Notification::where('user_id', $kineId)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Mark all notifications as read error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notifications as read'
            ], 500);
        }
    }

    /* ============================================
       PATIENT ACTIVITY FEED
       ============================================ */

    /**
     * Get patient activity feed
     */
    public function getActivityFeed(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $limit = $request->input('limit', 10);

            // Get patient IDs for this kine
            $patientIds = DB::table('kine_patient_assignments')
                ->where('kine_id', $kineId)
                ->pluck('patient_id');

            if ($patientIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Get activities from multiple sources
            $activities = collect();

            // 1. Exercise sessions
            $exerciseSessions = ExerciseSession::whereIn('patient_id', $patientIds)
                ->where('status', 'completed')
                ->with(['patient:id,first_name,last_name', 'exercise:id,name'])
                ->orderBy('session_date', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'type' => 'exercise_completed',
                        'patient_name' => $session->patient->first_name . ' ' . $session->patient->last_name,
                        'patient_id' => $session->patient_id,
                        'description' => 'a complété l\'exercice "' . ($session->exercise->name ?? 'Unknown') . '"',
                        'date' => $session->session_date->format('Y-m-d'),
                        'time' => $session->created_at->format('H:i'),
                        'icon' => 'Activity',
                        'color' => 'green',
                    ];
                });

            $activities = $activities->merge($exerciseSessions);

            // 2. Check-ins
            $checkIns = CheckIn::whereIn('patient_id', $patientIds)
                ->with(['patient:id,first_name,last_name', 'exercise'])
                ->orderBy('completed_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($checkIn) {
                    $exerciseName = $checkIn->exercise?->name ?? 'Unknown Exercise';
                    return [
                        'id' => $checkIn->id,
                        'type' => 'check_in',
                        'patient_name' => $checkIn->patient->first_name . ' ' . $checkIn->patient->last_name,
                        'patient_id' => $checkIn->patient_id,
                        'description' => 'a fait un check-in pour "' . $exerciseName . '"',
                        'date' => $checkIn->completed_at->format('Y-m-d'),
                        'time' => $checkIn->completed_at->format('H:i'),
                        'icon' => 'CheckCircle',
                        'color' => 'blue',
                    ];
                });

            $activities = $activities->merge($checkIns);

            // 3. Daily check-ins
            $dailyCheckins = DailyCheckin::whereIn('patient_id', $patientIds)
                ->with(['patient:id,first_name,last_name'])
                ->orderBy('checkin_date', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($checkin) {
                    return [
                        'id' => $checkin->id,
                        'type' => 'daily_checkin',
                        'patient_name' => $checkin->patient->first_name . ' ' . $checkin->patient->last_name,
                        'patient_id' => $checkin->patient_id,
                        'description' => 'a complété son check-in quotidien',
                        'date' => $checkin->checkin_date->format('Y-m-d'),
                        'time' => $checkin->created_at->format('H:i'),
                        'icon' => 'Calendar',
                        'color' => 'purple',
                    ];
                });

            $activities = $activities->merge($dailyCheckins);

            // 4. Milestones achieved
            $milestones = Milestone::whereIn('patient_id', $patientIds)
                ->where('achieved', true)
                ->with(['patient:id,first_name,last_name'])
                ->orderBy('achieved_date', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($milestone) {
                    return [
                        'id' => $milestone->id,
                        'type' => 'milestone',
                        'patient_name' => $milestone->patient->first_name . ' ' . $milestone->patient->last_name,
                        'patient_id' => $milestone->patient_id,
                        'description' => 'a atteint le jalon: ' . $milestone->title,
                        'date' => $milestone->achieved_date?->format('Y-m-d') ?? $milestone->created_at->format('Y-m-d'),
                        'time' => $milestone->created_at->format('H:i'),
                        'icon' => 'Award',
                        'color' => 'yellow',
                    ];
                });

            $activities = $activities->merge($milestones);

            // Sort all activities by date/time and limit
            $sortedActivities = $activities->sortByDesc(function ($activity) {
                return $activity['date'] . ' ' . $activity['time'];
            })->take($limit)->values();

            return response()->json([
                'success' => true,
                'data' => $sortedActivities
            ]);

        } catch (\Exception $e) {
            Log::error('Activity feed error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch activity feed'
            ], 500);
        }
    }

    /* ============================================
       DASHBOARD WIDGETS CONFIGURATION
       ============================================ */

    /**
     * Get dashboard widgets configuration
     */
    public function getWidgetsConfig(Request $request)
    {
        try {
            $defaultWidgets = $this->getDefaultWidgetsConfig();

            return response()->json([
                'success' => true,
                'data' => $defaultWidgets
            ]);
        } catch (\Exception $e) {
            Log::error('Get widgets config error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch widgets configuration'
            ], 500);
        }
    }

    /**
     * Get default widgets configuration
     */
    private function getDefaultWidgetsConfig(): array
    {
        return [
            [
                'id' => 'today_appointments',
                'title' => "Aujourd'hui",
                'description' => 'Appointments for today',
                'enabled' => true,
                'position' => 1,
                'size' => 'medium',
                'type' => 'appointments',
                'refresh_interval' => 300,
            ],
            [
                'id' => 'today_stats',
                'title' => 'Statistiques du Jour',
                'description' => "Today's statistics",
                'enabled' => true,
                'position' => 2,
                'size' => 'small',
                'type' => 'stats',
                'refresh_interval' => 600,
            ],
            [
                'id' => 'recent_patients',
                'title' => 'Patients Récents',
                'description' => 'Recently active patients',
                'enabled' => true,
                'position' => 3,
                'size' => 'medium',
                'type' => 'patients',
                'refresh_interval' => 900,
            ],
            [
                'id' => 'notifications',
                'title' => 'Notifications',
                'description' => 'Recent notifications',
                'enabled' => true,
                'position' => 4,
                'size' => 'medium',
                'type' => 'notifications',
                'refresh_interval' => 60,
            ],
            [
                'id' => 'upcoming_milestones',
                'title' => 'Jalons à Venir',
                'description' => 'Upcoming patient milestones',
                'enabled' => true,
                'position' => 5,
                'size' => 'small',
                'type' => 'milestones',
                'refresh_interval' => 1800,
            ],
            [
                'id' => 'recent_exercises',
                'title' => 'Exercices Récents',
                'description' => 'Recently completed exercises',
                'enabled' => true,
                'position' => 6,
                'size' => 'medium',
                'type' => 'exercises',
                'refresh_interval' => 1200,
            ],
            [
                'id' => 'activity_feed',
                'title' => 'Activité des Patients',
                'description' => 'Patient activity feed',
                'enabled' => true,
                'position' => 7,
                'size' => 'large',
                'type' => 'activity',
                'refresh_interval' => 300,
            ],
        ];
    }

    /* ============================================
       HELPER METHODS
       ============================================ */

    /**
     * Get patient count by status
     */
    private function getPatientCountByStatus(string $kineId): array
    {
        return [
            'active' => User::where('role', 'patient')
                ->whereHas('assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->where('is_active', true)
                ->count(),
            'inactive' => User::where('role', 'patient')
                ->whereHas('assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->where('is_active', false)
                ->count(),
            'new_this_month' => User::where('role', 'patient')
                ->whereHas('assignedKine', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()])
                ->count(),
        ];
    }

    /**
     * Get appointment statistics
     */
    private function getAppointmentStats(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'total' => Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })->count(),
            'completed' => Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })->where('status', 'completed')->count(),
            'cancelled' => Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })->where('status', 'cancelled')->count(),
            'scheduled' => Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })->where('status', 'scheduled')->count(),
        ];
    }
}
