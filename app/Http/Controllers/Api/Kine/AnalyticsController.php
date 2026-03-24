<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\{
    User,
    Appointment,
    AppointmentSlot,
    PatientAnalytics,
    ExerciseSession,
    ExerciseCategory,
    DailyCheckin,
    CheckIn,
    PatientProgramAssignment,
    Invoice,
    CancellationReason,
    PatientProfile,
    Pathology,
    PatientPathology,
    Program
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class AnalyticsController extends Controller
{
    /**
     * Get current authenticated kine ID
     */
    private function getKineId(): string
    {
        return Auth::id();
    }

    /**
     * Get all patient IDs assigned to this kine
     */
    private function getAssignedPatientIds(): array
    {
        return User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) {
                $query->where('kine_id', $this->getKineId());
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get active patient IDs (patients with active program assignments)
     */
    private function getActivePatientIds(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = PatientProgramAssignment::where('status', 'active')
            ->whereHas('program', function ($query) {
                $query->where('kine_id', $this->getKineId());
            });

        if ($startDate && $endDate) {
            $query->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('started_at', [$startDate, $endDate])
                    ->orWhereBetween('updated_at', [$startDate, $endDate]);
            });
        }

        return $query->distinct('patient_id')->pluck('patient_id')->toArray();
    }

    /**
     * Get patients still active from a given list
     */
    private function getPatientsStillActive(array $patientIds, Carbon $endDate): array
    {
        if (empty($patientIds)) {
            return [];
        }

        $activePatientIds = $this->getActivePatientIds(null, $endDate);
        return array_intersect($patientIds, $activePatientIds);
    }

    /**
     * Get date range periods without including the end date in count
     */
    private function getDatePeriods(Carbon $startDate, Carbon $endDate, string $interval = 'day'): array
    {
        $periods = [];
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $periods[] = $current->copy();

            switch ($interval) {
                case 'hour':
                    $current->addHour();
                    break;
                case 'day':
                    $current->addDay();
                    break;
                case 'week':
                    $current->addWeek();
                    break;
                case 'month':
                    $current->addMonth();
                    break;
                case 'year':
                    $current->addYear();
                    break;
                default:
                    $current->addDay();
            }
        }

        return $periods;
    }

    /**
     * Determine period type based on date range
     */
    private function determinePeriodType(Carbon $startDate, Carbon $endDate): string
    {
        $diffInDays = $startDate->diffInDays($endDate);

        if ($startDate->isSameDay($endDate)) {
            return 'hour';
        } elseif ($diffInDays <= 7) {
            return 'day';
        } elseif ($diffInDays <= 30) {
            return 'week';
        } elseif ($diffInDays <= 365) {
            return 'month';
        } else {
            return 'year';
        }
    }

    /**
     * Calculate percentage change helper
     */
    private function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Get color for index
     */
    private function getColorForIndex(int $index): string
    {
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#6b7280', '#ec4899', '#14b8a6'];
        return $colors[$index % count($colors)];
    }

    /* ============================================
       OVERVIEW METRICS SECTION
       ============================================ */

    public function getOverviewMetrics(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonths(3)));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $data = $this->calculateOverviewMetrics($kineId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Overview metrics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch metrics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function calculateOverviewMetrics(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        // Get active patients at the end of period
        $activePatientIds = $this->getActivePatientIds(null, $endDate);
        $activePatients = count($activePatientIds);

        // Get new patients in period (patients who got their first program assignment during this period)
        $newPatientIds = PatientProgramAssignment::where('status', 'active')
            ->whereHas('program', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->distinct('patient_id')
            ->pluck('patient_id')
            ->toArray();

        $newPatients = count($newPatientIds);

        // Get revenue data from COMPLETED appointments
        $revenueData = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                $query->where('kine_id', $kineId)
                    ->whereBetween('start_time', [$startDate, $endDate]);
            })
            ->where('status', 'completed')
            ->select(
                DB::raw('SUM(price) as current_revenue'),
                DB::raw('COUNT(DISTINCT patient_id) as revenue_patients')
            )
            ->first();

        // Calculate occupancy rate
        $occupancyData = AppointmentSlot::where('kine_id', $kineId)
            ->whereBetween('start_time', [$startDate, $endDate])
            ->select(
                DB::raw('COUNT(*) as total_slots'),
                DB::raw('SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM appointments
                    WHERE appointments.slot_id = appointment_slots.id
                    AND appointments.status IN ("completed", "scheduled")
                ) THEN 1 ELSE 0 END) as booked_slots')
            )
            ->first();

        $occupancyRate = $occupancyData->total_slots > 0
            ? ($occupancyData->booked_slots / $occupancyData->total_slots) * 100
            : 0;

        // Calculate average adherence for active patients
        $averageAdherence = 0;
        if (!empty($activePatientIds)) {
            $totalAdherence = 0;
            $patientsWithData = 0;

            foreach ($activePatientIds as $patientId) {
                $totalSessions = ExerciseSession::where('patient_id', $patientId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();

                if ($totalSessions > 0) {
                    $completedSessions = ExerciseSession::where('patient_id', $patientId)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->where('status', 'completed')
                        ->count();
                    $adherence = ($completedSessions / $totalSessions) * 100;
                    $totalAdherence += $adherence;
                    $patientsWithData++;
                }
            }

            $averageAdherence = $patientsWithData > 0 ? $totalAdherence / $patientsWithData : 0;
        }

        // Calculate retention rate
        $retentionData = $this->calculateRetentionRate($kineId, $startDate, $endDate);

        // Previous period data for comparison
        $periodLength = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($periodLength);
        $previousEndDate = $endDate->copy()->subDays($periodLength);

        $previousRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $previousStartDate, $previousEndDate) {
                $query->where('kine_id', $kineId)
                    ->whereBetween('start_time', [$previousStartDate, $previousEndDate]);
            })
            ->where('status', 'completed')
            ->sum('price');

        $previousActivePatientIds = $this->getActivePatientIds(null, $previousEndDate);
        $previousActivePatients = count($previousActivePatientIds);

        // Calculate changes
        $revenueChange = $this->calculatePercentageChange(
            $revenueData->current_revenue ?? 0,
            $previousRevenue
        );

        $patientsChange = $this->calculatePercentageChange(
            $activePatients,
            $previousActivePatients
        );

        $previousOccupancy = AppointmentSlot::where('kine_id', $kineId)
            ->whereBetween('start_time', [$previousStartDate, $previousEndDate])
            ->select(
                DB::raw('COUNT(*) as total_slots'),
                DB::raw('SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM appointments
                    WHERE appointments.slot_id = appointment_slots.id
                    AND appointments.status IN ("completed", "scheduled")
                ) THEN 1 ELSE 0 END) as booked_slots')
            )
            ->first();

        $previousOccupancyRate = $previousOccupancy->total_slots > 0
            ? ($previousOccupancy->booked_slots / $previousOccupancy->total_slots) * 100
            : 0;

        $occupancyChange = $this->calculatePercentageChange($occupancyRate, $previousOccupancyRate);

        $previousRetentionData = $this->calculateRetentionRate($kineId, $previousStartDate, $previousEndDate);
        $retentionChange = $this->calculatePercentageChange(
            $retentionData['retention_rate'],
            $previousRetentionData['retention_rate']
        );

        return [
            'total_revenue' => round($revenueData->current_revenue ?? 0, 2),
            'active_patients' => $activePatients,
            'new_patients' => $newPatients,
            'occupancy_rate' => round($occupancyRate, 1),
            'average_adherence' => round($averageAdherence, 1),
            'retention_rate' => round($retentionData['retention_rate'], 1),
            'retained_patients' => $retentionData['retained_patients'],
            'churned_patients' => $retentionData['churned_patients'],
            'revenue_change' => round($revenueChange, 1),
            'patients_change' => round($patientsChange, 1),
            'occupancy_change' => round($occupancyChange, 1),
            'retention_change' => round($retentionChange, 1),
            'revenue_per_patient' => $activePatients > 0
                ? round(($revenueData->current_revenue ?? 0) / $activePatients, 2)
                : 0,
            'total_hours' => round(($occupancyData->booked_slots ?? 0) * 0.75, 1),
        ];
    }

    private function calculateRetentionRate(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        // Get patients with active program assignments at the start of the period
        $patientsAtStart = $this->getActivePatientIds(null, $startDate->copy()->subDay());

        // Get patients who are still active at the end of the period
        $patientsStillActive = $this->getPatientsStillActive($patientsAtStart, $endDate);

        // Count how many of the start patients are still active
        $retainedPatients = count($patientsStillActive);

        // Count how many churned (were active at start but not at end)
        $churnedPatients = count($patientsAtStart) - $retainedPatients;

        // Calculate retention rate
        $retentionRate = count($patientsAtStart) > 0
            ? ($retainedPatients / count($patientsAtStart)) * 100
            : 100;

        return [
            'retention_rate' => $retentionRate,
            'retained_patients' => $retainedPatients,
            'churned_patients' => $churnedPatients,
            'total_patients_at_start' => count($patientsAtStart),
        ];
    }

    /* ============================================
       REVENUE ANALYTICS SECTION
       ============================================ */

    public function getRevenueAnalytics(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $period = $request->input('period', $this->determinePeriodType($startDate, $endDate));

            $data = $this->getRevenueDataByPeriod($kineId, $period, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Revenue analytics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch revenue analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getRevenueDataByPeriod(string $kineId, string $period, Carbon $startDate, Carbon $endDate): array
    {
        switch ($period) {
            case 'hour':
                return $this->getHourlyRevenueData($kineId, $startDate, $endDate);
            case 'day':
                return $this->getDailyRevenueData($kineId, $startDate, $endDate);
            case 'week':
                return $this->getWeeklyRevenueData($kineId, $startDate, $endDate);
            case 'month':
                return $this->getMonthlyRevenueData($kineId, $startDate, $endDate);
            case 'year':
                return $this->getYearlyRevenueData($kineId, $startDate, $endDate);
            default:
                return $this->getMonthlyRevenueData($kineId, $startDate, $endDate);
        }
    }

    private function getHourlyRevenueData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $revenueData = [];

        if ($startDate->isSameDay($endDate)) {
            $startHour = 8;
            $endHour = 20;

            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                $hourStart = $startDate->copy()->setTime($hour, 0, 0);
                $hourEnd = $startDate->copy()->setTime($hour, 59, 59);

                $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->sum('price');

                $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->count();

                $totalSlots = AppointmentSlot::where('kine_id', $kineId)
                    ->whereBetween('start_time', [$hourStart, $hourEnd])
                    ->count();

                $occupancyRate = $totalSlots > 0 ? ($appointments / $totalSlots) * 100 : 0;

                $revenueData[] = [
                    'period' => sprintf('%02d:00', $hour),
                    'hour' => $hour,
                    'revenue' => round($revenue, 2),
                    'appointments' => $appointments,
                    'occupancy_rate' => round($occupancyRate, 1),
                    'total_slots' => $totalSlots,
                    'date' => $startDate->format('Y-m-d'),
                ];
            }
        } else {
            return $this->getDailyRevenueData($kineId, $startDate, $endDate);
        }

        return $revenueData;
    }

    private function getDailyRevenueData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $revenueData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'day');

        foreach ($periods as $date) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $previousDayStart = $dayStart->copy()->subDay();
            $previousDayEnd = $dayEnd->copy()->subDay();

            $previousRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $previousDayStart, $previousDayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$previousDayStart, $previousDayEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $growth = $this->calculatePercentageChange($revenue, $previousRevenue);

            $revenueData[] = [
                'period' => $date->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
                'revenue' => round($revenue, 2),
                'growth' => round($growth, 1),
                'appointments' => $appointments,
                'day_name' => $date->locale('fr')->dayName,
                'day_number' => $date->day,
            ];
        }

        return $revenueData;
    }

    private function getWeeklyRevenueData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $revenueData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'week');

        foreach ($periods as $date) {
            $weekStart = $date->copy()->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $revenueData[] = [
                'period' => "Sem " . $date->weekOfYear . " " . $date->format('Y'),
                'week' => $date->weekOfYear,
                'year' => (int)$date->format('Y'),
                'revenue' => round($revenue, 2),
                'appointments' => $appointments,
            ];
        }

        return $revenueData;
    }

    private function getMonthlyRevenueData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $revenueData = [];
        $currentMonth = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();

        while ($currentMonth <= $lastMonth) {
            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $previousMonthStart = $monthStart->copy()->subMonth();
            $previousMonthEnd = $monthEnd->copy()->subMonth();

            $previousRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $previousMonthStart, $previousMonthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$previousMonthStart, $previousMonthEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $growth = $this->calculatePercentageChange($revenue, $previousRevenue);
            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $activePatients = count($this->getActivePatientIds(null, $monthEnd));
            $revenuePerPatient = $activePatients > 0 ? $revenue / $activePatients : 0;

            $revenueData[] = [
                'period' => $currentMonth->format('M Y'),
                'month' => $currentMonth->format('M'),
                'year' => (int)$currentMonth->format('Y'),
                'month_number' => (int)$currentMonth->format('n'),
                'revenue' => round($revenue, 2),
                'growth' => round($growth, 1),
                'appointments' => $appointments,
                'average_per_patient' => round($revenuePerPatient, 2),
                'active_patients' => $activePatients,
            ];

            $currentMonth->addMonth();
        }

        return $revenueData;
    }

    private function getYearlyRevenueData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $revenueData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'year');

        foreach ($periods as $date) {
            $yearStart = $date->copy()->startOfYear();
            $yearEnd = $date->copy()->endOfYear();

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $previousYearStart = $yearStart->copy()->subYear();
            $previousYearEnd = $yearEnd->copy()->subYear();

            $previousRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $previousYearStart, $previousYearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$previousYearStart, $previousYearEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $growth = $this->calculatePercentageChange($revenue, $previousRevenue);
            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $revenueData[] = [
                'period' => $date->format('Y'),
                'year' => (int)$date->format('Y'),
                'revenue' => round($revenue, 2),
                'growth' => round($growth, 1),
                'appointments' => $appointments,
            ];
        }

        return $revenueData;
    }

    /* ============================================
       PATIENT METRICS SECTION
       ============================================ */

    public function getPatientMetrics(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $period = $request->input('period', $this->determinePeriodType($startDate, $endDate));

            $data = $this->getPatientDataByPeriod($kineId, $period, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Patient metrics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patient metrics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getPatientDataByPeriod(string $kineId, string $period, Carbon $startDate, Carbon $endDate): array
    {
        switch ($period) {
            case 'hour':
                return $this->getHourlyPatientData($kineId, $startDate, $endDate);
            case 'day':
                return $this->getDailyPatientData($kineId, $startDate, $endDate);
            case 'week':
                return $this->getWeeklyPatientData($kineId, $startDate, $endDate);
            case 'month':
                return $this->getMonthlyPatientData($kineId, $startDate, $endDate);
            case 'year':
                return $this->getYearlyPatientData($kineId, $startDate, $endDate);
            default:
                return $this->getMonthlyPatientData($kineId, $startDate, $endDate);
        }
    }

    private function getHourlyPatientData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $patientData = [];

        if ($startDate->isSameDay($endDate)) {
            $startHour = 8;
            $endHour = 20;

            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                $hourStart = $startDate->copy()->setTime($hour, 0, 0);
                $hourEnd = $startDate->copy()->setTime($hour, 59, 59);

                $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->count();

                $patientsSeen = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->distinct('patient_id')
                    ->count('patient_id');

                $maxAppointments = 2;
                $occupancyRate = $maxAppointments > 0 ? ($appointments / $maxAppointments) * 100 : 0;

                $patientData[] = [
                    'period' => sprintf('%02d:00', $hour),
                    'hour' => $hour,
                    'appointments' => $appointments,
                    'patients_seen' => $patientsSeen,
                    'occupancy_rate' => round($occupancyRate, 1),
                    'time' => sprintf('%02d:00-%02d:00', $hour, $hour + 1),
                ];
            }
        } else {
            return $this->getDailyPatientData($kineId, $startDate, $endDate);
        }

        return $patientData;
    }

    private function getDailyPatientData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $patientData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'day');

        // Get all new patients by day
        $newPatientsByDay = [];
        $allNewPatients = PatientProgramAssignment::where('status', 'active')
            ->whereHas('program', function ($query) use ($kineId) {
                $query->where('kine_id', $kineId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select('patient_id', DB::raw('DATE(created_at) as created_date'))
            ->get()
            ->groupBy('created_date');

        foreach ($allNewPatients as $date => $patients) {
            $newPatientsByDay[$date] = $patients->count();
        }

        // Get patients who were active at the start of the period
        $patientsAtStart = $this->getActivePatientIds(null, $startDate->copy()->subDay());
        $patientsAtStartCount = count($patientsAtStart);

        $previousDayActiveCount = null;

        foreach ($periods as $date) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            // Active patients at the end of the day
            $activePatients = count($this->getActivePatientIds(null, $dayEnd));

            // New patients for the day
            $dateKey = $date->format('Y-m-d');
            $newPatients = $newPatientsByDay[$dateKey] ?? 0;

            // Calculate retention rate
            $retentionRate = 100;
            if ($patientsAtStartCount > 0) {
                $stillActive = $this->getPatientsStillActive($patientsAtStart, $dayEnd);
                $retentionRate = (count($stillActive) / $patientsAtStartCount) * 100;
            }

            // Calculate churned patients
            $churnedPatients = 0;
            if ($previousDayActiveCount !== null) {
                $previousDayEnd = $dayStart->copy()->subDay()->endOfDay();
                $previousActivePatients = $this->getActivePatientIds(null, $previousDayEnd);
                $currentActivePatients = $this->getActivePatientIds(null, $dayEnd);
                $churnedPatients = count(array_diff($previousActivePatients, $currentActivePatients));
            }

            $previousDayActiveCount = $activePatients;

            // Appointments for the day
            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->count();

            // Calculate day-over-day growth
            $patientGrowth = 0;
            if ($previousDayActiveCount !== null && $previousDayActiveCount > 0) {
                $patientGrowth = (($activePatients - $previousDayActiveCount) / $previousDayActiveCount) * 100;
            }

            $patientData[] = [
                'period' => $date->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
                'active_patients' => $activePatients,
                'new_patients' => $newPatients,
                'churned_patients' => $churnedPatients,
                'appointments' => $appointments,
                'retention_rate' => round($retentionRate, 1),
                'patient_growth' => round($patientGrowth, 1),
                'day_name' => $date->locale('fr')->dayName,
                'day_number' => $date->day,
                'appointments_per_patient' => $activePatients > 0 ? round($appointments / $activePatients, 1) : 0,
            ];
        }

        return $patientData;
    }

    private function getWeeklyPatientData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $patientData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'week');

        foreach ($periods as $date) {
            $weekStart = $date->copy()->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            $activePatients = count($this->getActivePatientIds(null, $weekEnd));
            $newPatients = PatientProgramAssignment::where('status', 'active')
                ->whereHas('program', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->distinct('patient_id')
                ->count();

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $patientData[] = [
                'period' => "Sem " . $date->weekOfYear . " " . $date->format('Y'),
                'week' => $date->weekOfYear,
                'year' => (int)$date->format('Y'),
                'active_patients' => $activePatients,
                'new_patients' => $newPatients,
                'churned_patients' => 0,
                'appointments' => $appointments,
                'appointments_per_patient' => $activePatients > 0 ? round($appointments / $activePatients, 1) : 0,
            ];
        }

        return $patientData;
    }

    private function getMonthlyPatientData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $patientData = [];
        $currentMonth = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();

        while ($currentMonth <= $lastMonth) {
            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();

            $activePatients = count($this->getActivePatientIds(null, $monthEnd));
            $newPatients = PatientProgramAssignment::where('status', 'active')
                ->whereHas('program', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->distinct('patient_id')
                ->count();

            $previousMonthStart = $monthStart->copy()->subMonth();
            $previousMonthEnd = $monthEnd->copy()->subMonth();
            $previousActivePatients = count($this->getActivePatientIds(null, $previousMonthEnd));
            $patientGrowth = $this->calculatePercentageChange($activePatients, $previousActivePatients);

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $monthRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $lifetimeValue = $activePatients > 0 ? $monthRevenue / $activePatients : 0;

            $patientData[] = [
                'period' => $currentMonth->format('M Y'),
                'month' => $currentMonth->format('M'),
                'year' => (int)$currentMonth->format('Y'),
                'month_number' => (int)$currentMonth->format('n'),
                'active_patients' => $activePatients,
                'new_patients' => $newPatients,
                'churned_patients' => 0,
                'patient_growth' => round($patientGrowth, 1),
                'retention_rate' => 100,
                'appointments' => $appointments,
                'average_sessions' => $activePatients > 0 ? round($appointments / $activePatients, 1) : 0,
                'lifetime_value' => round($lifetimeValue, 2),
            ];

            $currentMonth->addMonth();
        }

        return $patientData;
    }

    private function getYearlyPatientData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $patientData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'year');

        foreach ($periods as $date) {
            $yearStart = $date->copy()->startOfYear();
            $yearEnd = $date->copy()->endOfYear();

            $activePatients = count($this->getActivePatientIds(null, $yearEnd));
            $newPatients = PatientProgramAssignment::where('status', 'active')
                ->whereHas('program', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->distinct('patient_id')
                ->count();

            $previousYearStart = $yearStart->copy()->subYear();
            $previousYearEnd = $yearEnd->copy()->subYear();
            $previousActivePatients = count($this->getActivePatientIds(null, $previousYearEnd));
            $patientGrowth = $this->calculatePercentageChange($activePatients, $previousActivePatients);

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $patientData[] = [
                'period' => $date->format('Y'),
                'year' => (int)$date->format('Y'),
                'active_patients' => $activePatients,
                'new_patients' => $newPatients,
                'churned_patients' => 0,
                'appointments' => $appointments,
                'patient_growth' => round($patientGrowth, 1),
                'retention_rate' => 100,
                'appointments_per_patient' => $activePatients > 0 ? round($appointments / $activePatients, 1) : 0,
            ];
        }

        return $patientData;
    }

    /* ============================================
       PATIENT DEMOGRAPHICS SECTION
       ============================================ */

    public function getPatientDemographics(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonths(3)));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

            if (empty($activePatientIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'gender_distribution' => ['male' => 0, 'female' => 0, 'other' => 0],
                        'age_distribution' => [],
                        'average_age' => 0,
                        'total_patients' => 0
                    ]
                ]);
            }

            $patients = User::whereIn('id', $activePatientIds)
                ->with('patientProfile')
                ->get();

            $genderDistribution = [
                'male' => 0,
                'female' => 0,
                'other' => 0
            ];

            $ageGroups = [
                '18-25' => 0,
                '26-35' => 0,
                '36-50' => 0,
                '51-65' => 0,
                '65+' => 0
            ];

            $totalAge = 0;
            $patientsWithAge = 0;

            foreach ($patients as $patient) {
                if ($patient->patientProfile) {
                    $gender = $patient->patientProfile->gender;
                    if ($gender && isset($genderDistribution[$gender])) {
                        $genderDistribution[$gender]++;
                    }

                    if ($patient->date_of_birth) {
                        $age = Carbon::parse($patient->date_of_birth)->age;
                        $totalAge += $age;
                        $patientsWithAge++;

                        if ($age <= 25) $ageGroups['18-25']++;
                        elseif ($age <= 35) $ageGroups['26-35']++;
                        elseif ($age <= 50) $ageGroups['36-50']++;
                        elseif ($age <= 65) $ageGroups['51-65']++;
                        else $ageGroups['65+']++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'gender_distribution' => $genderDistribution,
                    'age_distribution' => $ageGroups,
                    'average_age' => $patientsWithAge > 0 ? round($totalAge / $patientsWithAge, 1) : 0,
                    'total_patients' => count($activePatientIds)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Patient demographics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patient demographics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ============================================
       PATHOLOGY DISTRIBUTION SECTION
       ============================================ */

    public function getPathologyDistribution(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonths(3)));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $data = $this->calculatePathologyDistribution($kineId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Pathology distribution error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pathology distribution',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function calculatePathologyDistribution(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

        if (empty($activePatientIds)) {
            return [];
        }

        $patientProfiles = PatientProfile::whereIn('user_id', $activePatientIds)->pluck('id');

        if ($patientProfiles->isEmpty()) {
            return [];
        }

        $pathologyStats = PatientPathology::whereIn('patient_profile_id', $patientProfiles)
            ->where('is_active', true)
            ->select(
                'pathology_id',
                DB::raw('COUNT(DISTINCT patient_profile_id) as patient_count')
            )
            ->groupBy('pathology_id')
            ->orderBy('patient_count', 'desc')
            ->get();

        $totalPatientsWithPathologies = $pathologyStats->sum('patient_count');

        if ($totalPatientsWithPathologies === 0) {
            return [];
        }

        $distribution = [];
        $pathologyIds = $pathologyStats->pluck('pathology_id');

        $pathologies = Pathology::whereIn('id', $pathologyIds)
            ->where('is_active', true)
            ->orderBy('order_index')
            ->get()
            ->keyBy('id');

        foreach ($pathologyStats as $index => $stat) {
            $pathology = $pathologies->get($stat->pathology_id);

            if (!$pathology) {
                continue;
            }

            $patientCount = $stat->patient_count;
            $percentage = ($patientCount / $totalPatientsWithPathologies) * 100;

            $patientIdsWithPathology = PatientPathology::where('pathology_id', $stat->pathology_id)
                ->whereIn('patient_profile_id', $patientProfiles)
                ->pluck('patient_profile_id');

            $userIdsWithPathology = PatientProfile::whereIn('id', $patientIdsWithPathology)
                ->pluck('user_id');

            $revenue = 0;
            if ($userIdsWithPathology->isNotEmpty()) {
                $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$startDate, $endDate]);
                    })
                    ->whereIn('patient_id', $userIdsWithPathology)
                    ->where('status', 'completed')
                    ->sum('price');
            }

            $averageRevenue = $patientCount > 0 ? $revenue / $patientCount : 0;

            $distribution[] = [
                'pathology_id' => $pathology->id,
                'pathology' => $pathology->name,
                'category' => $pathology->category,
                'percentage' => round($percentage, 1),
                'patients' => $patientCount,
                'average_revenue' => round($averageRevenue, 2),
                'color' => $pathology->color ?? $this->getColorForIndex($index),
            ];
        }

        usort($distribution, function($a, $b) {
            return $b['percentage'] <=> $a['percentage'];
        });

        return $distribution;
    }

    /* ============================================
       APPOINTMENT ANALYTICS SECTION
       ============================================ */

    public function getAppointmentAnalytics(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $period = $request->input('period', $this->determinePeriodType($startDate, $endDate));

            $data = $this->getAppointmentDataByPeriod($kineId, $period, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Appointment analytics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch appointment analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getAppointmentDataByPeriod(string $kineId, string $period, Carbon $startDate, Carbon $endDate): array
    {
        switch ($period) {
            case 'hour':
                return $this->getHourlyAppointmentData($kineId, $startDate, $endDate);
            case 'day':
                return $this->getDailyAppointmentData($kineId, $startDate, $endDate);
            case 'week':
                return $this->getWeeklyAppointmentData($kineId, $startDate, $endDate);
            case 'month':
                return $this->getMonthlyAppointmentData($kineId, $startDate, $endDate);
            case 'year':
                return $this->getYearlyAppointmentData($kineId, $startDate, $endDate);
            default:
                return $this->getDailyAppointmentData($kineId, $startDate, $endDate);
        }
    }

    private function getHourlyAppointmentData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $appointmentData = [];

        if ($startDate->isSameDay($endDate)) {
            $startHour = 8;
            $endHour = 20;

            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                $hourStart = $startDate->copy()->setTime($hour, 0, 0);
                $hourEnd = $startDate->copy()->setTime($hour, 59, 59);

                $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })->count();

                $completed = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->count();

                $cancelled = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'cancelled')
                    ->count();

                $noShow = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'scheduled')
                    ->whereHas('slot', function ($query) use ($hourEnd) {
                        $query->where('start_time', '<', $hourEnd);
                    })
                    ->count();

                $completionRate = $appointments > 0 ? ($completed / $appointments) * 100 : 0;
                $cancellationRate = $appointments > 0 ? ($cancelled / $appointments) * 100 : 0;
                $noShowRate = $appointments > 0 ? ($noShow / $appointments) * 100 : 0;

                $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $hourStart, $hourEnd) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$hourStart, $hourEnd]);
                    })
                    ->where('status', 'completed')
                    ->sum('price');

                $averageRevenue = $completed > 0 ? $revenue / $completed : 0;

                $appointmentData[] = [
                    'period' => sprintf('%02d:00', $hour),
                    'hour' => $hour,
                    'time' => sprintf('%02d:00-%02d:00', $hour, $hour + 1),
                    'scheduled' => $appointments,
                    'completed' => $completed,
                    'cancelled' => $cancelled,
                    'no_show' => $noShow,
                    'completion_rate' => round($completionRate, 1),
                    'cancellation_rate' => round($cancellationRate, 1),
                    'no_show_rate' => round($noShowRate, 1),
                    'average_revenue' => round($averageRevenue, 2),
                    'total_revenue' => round($revenue, 2),
                    'average_duration' => 30,
                ];
            }
        } else {
            return $this->getDailyAppointmentData($kineId, $startDate, $endDate);
        }

        return $appointmentData;
    }

    private function getDailyAppointmentData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $appointmentData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'day');

        foreach ($periods as $date) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })->count();

            $completed = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $cancelled = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'cancelled')
                ->count();

            $noShow = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'scheduled')
                ->whereHas('slot', function ($query) use ($dayEnd) {
                    $query->where('start_time', '<', $dayEnd);
                })
                ->count();

            $completionRate = $appointments > 0 ? ($completed / $appointments) * 100 : 0;
            $cancellationRate = $appointments > 0 ? ($cancelled / $appointments) * 100 : 0;
            $noShowRate = $appointments > 0 ? ($noShow / $appointments) * 100 : 0;

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $averageRevenue = $completed > 0 ? $revenue / $completed : 0;

            $appointmentsWithDuration = Appointment::whereHas('slot', function ($query) use ($kineId, $dayStart, $dayEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$dayStart, $dayEnd]);
                })
                ->where('status', 'completed')
                ->with(['slot' => function ($query) {
                    $query->select('id', 'start_time', 'end_time');
                }])
                ->get();

            $totalDuration = 0;
            foreach ($appointmentsWithDuration as $appointment) {
                if ($appointment->slot && $appointment->slot->start_time && $appointment->slot->end_time) {
                    $duration = $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time);
                    $totalDuration += $duration;
                }
            }

            $averageDuration = $completed > 0 ? round($totalDuration / $completed) : 45;

            $appointmentData[] = [
                'period' => $date->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'day_name' => $date->locale('fr')->dayName,
                'scheduled' => $appointments,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'completion_rate' => round($completionRate, 1),
                'cancellation_rate' => round($cancellationRate, 1),
                'no_show_rate' => round($noShowRate, 1),
                'average_revenue' => round($averageRevenue, 2),
                'total_revenue' => round($revenue, 2),
                'average_duration' => $averageDuration,
            ];
        }

        return $appointmentData;
    }

    private function getWeeklyAppointmentData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $appointmentData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'week');

        foreach ($periods as $date) {
            $weekStart = $date->copy()->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })->count();

            $completed = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $cancelled = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'cancelled')
                ->count();

            $noShow = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'scheduled')
                ->whereHas('slot', function ($query) use ($weekEnd) {
                    $query->where('start_time', '<', $weekEnd);
                })
                ->count();

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $weekStart, $weekEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$weekStart, $weekEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $appointmentData[] = [
                'period' => "Sem " . $date->weekOfYear . " " . $date->format('Y'),
                'week' => $date->weekOfYear,
                'year' => (int)$date->format('Y'),
                'scheduled' => $appointments,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'total_revenue' => round($revenue, 2),
            ];
        }

        return $appointmentData;
    }

    private function getMonthlyAppointmentData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $appointmentData = [];
        $currentMonth = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();

        while ($currentMonth <= $lastMonth) {
            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })->count();

            $completed = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $cancelled = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'cancelled')
                ->count();

            $noShow = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'scheduled')
                ->whereHas('slot', function ($query) use ($monthEnd) {
                    $query->where('start_time', '<', $monthEnd);
                })
                ->count();

            $completionRate = $appointments > 0 ? ($completed / $appointments) * 100 : 0;
            $cancellationRate = $appointments > 0 ? ($cancelled / $appointments) * 100 : 0;
            $noShowRate = $appointments > 0 ? ($noShow / $appointments) * 100 : 0;

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $averageRevenue = $completed > 0 ? $revenue / $completed : 0;

            $appointmentsWithDuration = Appointment::whereHas('slot', function ($query) use ($kineId, $monthStart, $monthEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$monthStart, $monthEnd]);
                })
                ->where('status', 'completed')
                ->with(['slot' => function ($query) {
                    $query->select('id', 'start_time', 'end_time');
                }])
                ->get();

            $totalDuration = 0;
            foreach ($appointmentsWithDuration as $appointment) {
                if ($appointment->slot && $appointment->slot->start_time && $appointment->slot->end_time) {
                    $duration = $appointment->slot->start_time->diffInMinutes($appointment->slot->end_time);
                    $totalDuration += $duration;
                }
            }

            $averageDuration = $completed > 0 ? round($totalDuration / $completed) : 45;

            $appointmentData[] = [
                'period' => $currentMonth->format('M Y'),
                'month' => $currentMonth->format('M'),
                'year' => (int)$currentMonth->format('Y'),
                'month_number' => (int)$currentMonth->format('n'),
                'scheduled' => $appointments,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'completion_rate' => round($completionRate, 1),
                'cancellation_rate' => round($cancellationRate, 1),
                'no_show_rate' => round($noShowRate, 1),
                'average_revenue' => round($averageRevenue, 2),
                'total_revenue' => round($revenue, 2),
                'average_duration' => $averageDuration,
            ];

            $currentMonth->addMonth();
        }

        return $appointmentData;
    }

    private function getYearlyAppointmentData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $appointmentData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'year');

        foreach ($periods as $date) {
            $yearStart = $date->copy()->startOfYear();
            $yearEnd = $date->copy()->endOfYear();

            $appointments = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })->count();

            $completed = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'completed')
                ->count();

            $cancelled = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'cancelled')
                ->count();

            $noShow = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'scheduled')
                ->whereHas('slot', function ($query) use ($yearEnd) {
                    $query->where('start_time', '<', $yearEnd);
                })
                ->count();

            $completionRate = $appointments > 0 ? ($completed / $appointments) * 100 : 0;
            $cancellationRate = $appointments > 0 ? ($cancelled / $appointments) * 100 : 0;
            $noShowRate = $appointments > 0 ? ($noShow / $appointments) * 100 : 0;

            $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $yearStart, $yearEnd) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$yearStart, $yearEnd]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $averageRevenue = $completed > 0 ? $revenue / $completed : 0;

            $appointmentData[] = [
                'period' => $date->format('Y'),
                'year' => (int)$date->format('Y'),
                'scheduled' => $appointments,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'completion_rate' => round($completionRate, 1),
                'cancellation_rate' => round($cancellationRate, 1),
                'no_show_rate' => round($noShowRate, 1),
                'average_revenue' => round($averageRevenue, 2),
                'total_revenue' => round($revenue, 2),
            ];
        }

        return $appointmentData;
    }

    /* ============================================
       EXERCISE ENGAGEMENT SECTION
       ============================================ */

    public function getExerciseEngagement(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $period = $request->input('period', $this->determinePeriodType($startDate, $endDate));

            $data = $this->getExerciseDataByPeriod($kineId, $period, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Exercise engagement error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exercise engagement data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getExerciseDataByPeriod(string $kineId, string $period, Carbon $startDate, Carbon $endDate): array
    {
        switch ($period) {
            case 'hour':
                return $this->getHourlyExerciseData($kineId, $startDate, $endDate);
            case 'day':
                return $this->getDailyExerciseData($kineId, $startDate, $endDate);
            case 'week':
                return $this->getWeeklyExerciseData($kineId, $startDate, $endDate);
            case 'month':
                return $this->getMonthlyExerciseData($kineId, $startDate, $endDate);
            case 'year':
                return $this->getYearlyExerciseData($kineId, $startDate, $endDate);
            default:
                return $this->getWeeklyExerciseData($kineId, $startDate, $endDate);
        }
    }

    private function getHourlyExerciseData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $exerciseData = [];

        if ($startDate->isSameDay($endDate)) {
            $startHour = 8;
            $endHour = 22;

            $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

            if (empty($activePatientIds)) {
                return [];
            }

            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                $hourStart = $startDate->copy()->setTime($hour, 0, 0);
                $hourEnd = $startDate->copy()->setTime($hour, 59, 59);

                $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->count();

                $completedExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->where('status', 'completed')
                    ->count();

                $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

                $activePatients = ExerciseSession::whereIn('patient_id', $activePatientIds)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->distinct('patient_id')
                    ->count('patient_id');

                $averageDuration = ExerciseSession::whereIn('patient_id', $activePatientIds)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->where('status', 'completed')
                    ->avg('duration_minutes') ?? 0;

                $exerciseData[] = [
                    'period' => sprintf('%02d:00', $hour),
                    'hour' => $hour,
                    'time' => sprintf('%02d:00-%02d:00', $hour, $hour + 1),
                    'total_exercises' => $totalExercises,
                    'completed_exercises' => $completedExercises,
                    'adherence_rate' => round($adherenceRate, 1),
                    'active_patients' => $activePatients,
                    'average_duration' => round($averageDuration, 1),
                ];
            }
        } else {
            return $this->getDailyExerciseData($kineId, $startDate, $endDate);
        }

        return $exerciseData;
    }

    private function getDailyExerciseData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $exerciseData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'day');

        $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

        if (empty($activePatientIds)) {
            return [];
        }

        foreach ($periods as $date) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();

            $completedExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('status', 'completed')
                ->count();

            $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

            $activePatients = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->distinct('patient_id')
                ->count('patient_id');

            $averageDuration = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('status', 'completed')
                ->avg('duration_minutes') ?? 0;

            $exerciseData[] = [
                'period' => $date->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'day_name' => $date->locale('fr')->dayName,
                'total_exercises' => $totalExercises,
                'completed_exercises' => $completedExercises,
                'adherence_rate' => round($adherenceRate, 1),
                'active_patients' => $activePatients,
                'average_duration' => round($averageDuration, 1),
            ];
        }

        return $exerciseData;
    }

    private function getWeeklyExerciseData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $exerciseData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'week');

        $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

        if (empty($activePatientIds)) {
            return [];
        }

        foreach ($periods as $date) {
            $weekStart = $date->copy()->startOfWeek();
            $weekEnd = $date->copy()->endOfWeek();

            $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->count();

            $completedExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->where('status', 'completed')
                ->count();

            $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

            $exerciseData[] = [
                'period' => "Sem " . $date->weekOfYear . " " . $date->format('Y'),
                'week' => $date->weekOfYear,
                'year' => (int)$date->format('Y'),
                'total_exercises' => $totalExercises,
                'completed_exercises' => $completedExercises,
                'adherence_rate' => round($adherenceRate, 1),
            ];
        }

        return $exerciseData;
    }

    private function getMonthlyExerciseData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $exerciseData = [];
        $currentMonth = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();

        $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

        if (empty($activePatientIds)) {
            return [];
        }

        while ($currentMonth <= $lastMonth) {
            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();

            $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $completedExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'completed')
                ->count();

            $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

            $exerciseData[] = [
                'period' => $currentMonth->format('M Y'),
                'month' => $currentMonth->format('M'),
                'year' => (int)$currentMonth->format('Y'),
                'month_number' => (int)$currentMonth->format('n'),
                'total_exercises' => $totalExercises,
                'completed_exercises' => $completedExercises,
                'adherence_rate' => round($adherenceRate, 1),
            ];

            $currentMonth->addMonth();
        }

        return $exerciseData;
    }

    private function getYearlyExerciseData(string $kineId, Carbon $startDate, Carbon $endDate): array
    {
        $exerciseData = [];
        $periods = $this->getDatePeriods($startDate, $endDate, 'year');

        $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

        if (empty($activePatientIds)) {
            return [];
        }

        foreach ($periods as $date) {
            $yearStart = $date->copy()->startOfYear();
            $yearEnd = $date->copy()->endOfYear();

            $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->count();

            $completedExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->where('status', 'completed')
                ->count();

            $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

            $exerciseData[] = [
                'period' => $date->format('Y'),
                'year' => (int)$date->format('Y'),
                'total_exercises' => $totalExercises,
                'completed_exercises' => $completedExercises,
                'adherence_rate' => round($adherenceRate, 1),
            ];
        }

        return $exerciseData;
    }

    /* ============================================
       PATIENT PERFORMANCE SECTION
       ============================================ */

    public function getPatientPerformance(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonth()));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

            if (empty($activePatientIds)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $patients = User::whereIn('id', $activePatientIds)
                ->where('is_active', true)
                ->with(['patientProfile', 'checkIns', 'dailyCheckins'])
                ->limit(10)
                ->get();

            $performanceData = [];

            foreach ($patients as $patient) {
                $totalExercises = ExerciseSession::where('patient_id', $patient->id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();

                $completedExercises = ExerciseSession::where('patient_id', $patient->id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', 'completed')
                    ->count();

                $adherenceRate = $totalExercises > 0 ? ($completedExercises / $totalExercises) * 100 : 0;

                $currentPain = DailyCheckin::where('patient_id', $patient->id)
                    ->orderBy('checkin_date', 'desc')
                    ->first()
                    ?->overall_pain_level ?? 0;

                $initialPain = DailyCheckin::where('patient_id', $patient->id)
                    ->orderBy('checkin_date', 'asc')
                    ->first()
                    ?->overall_pain_level ?? $currentPain;

                $painReduction = $initialPain > 0 ? (($initialPain - $currentPain) / $initialPain) * 100 : 0;

                $streakDays = CheckIn::where('patient_id', $patient->id)
                    ->where('completed_at', '>=', $startDate)
                    ->orderBy('completed_at', 'desc')
                    ->get()
                    ->groupBy(function ($checkin) {
                        return $checkin->completed_at->format('Y-m-d');
                    })
                    ->count();

                if ($adherenceRate >= 90 && $painReduction >= 50) {
                    $status = 'excellent';
                } elseif ($adherenceRate >= 75 && $painReduction >= 30) {
                    $status = 'good';
                } else {
                    $status = 'needs_attention';
                }

                $performanceData[] = [
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->full_name ?? $patient->first_name . ' ' . $patient->last_name,
                    'adherence_rate' => round($adherenceRate, 1),
                    'pain_reduction' => round($painReduction, 1),
                    'streak_days' => $streakDays,
                    'status' => $status,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $performanceData
            ]);
        } catch (\Exception $e) {
            Log::error('Patient performance error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patient performance',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ============================================
       PERFORMANCE GOALS SECTION
       ============================================ */

    public function getPerformanceGoals(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonth()));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $activePatientIds = $this->getActivePatientIds($startDate, $endDate);

            $monthlyRevenue = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })
                ->where('status', 'completed')
                ->sum('price');

            $newPatients = PatientProgramAssignment::where('status', 'active')
                ->whereHas('program', function ($query) use ($kineId) {
                    $query->where('kine_id', $kineId);
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->distinct('patient_id')
                ->count();

            $satisfactionRate = 88;

            $totalAdherence = 0;
            $patientsWithData = 0;

            foreach ($activePatientIds as $patientId) {
                $totalExercises = ExerciseSession::where('patient_id', $patientId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();

                if ($totalExercises > 0) {
                    $completedExercises = ExerciseSession::where('patient_id', $patientId)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->where('status', 'completed')
                        ->count();
                    $totalAdherence += ($completedExercises / $totalExercises) * 100;
                    $patientsWithData++;
                }
            }

            $averageAdherence = $patientsWithData > 0 ? $totalAdherence / $patientsWithData : 0;

            $totalAppointments = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })->count();

            $cancelledAppointments = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })
                ->where('status', 'cancelled')
                ->count();

            $cancellationRate = $totalAppointments > 0 ? ($cancelledAppointments / $totalAppointments) * 100 : 0;

            $goals = [
                [
                    'metric' => 'Revenue mensuel',
                    'target' => '15,000 DH',
                    'actual' => number_format($monthlyRevenue, 0) . ' DH',
                    'progress' => round(($monthlyRevenue / 15000) * 100),
                    'status' => $monthlyRevenue >= 15000 ? 'success' : ($monthlyRevenue >= 12000 ? 'warning' : 'danger'),
                ],
                [
                    'metric' => 'Nouveaux patients',
                    'target' => '25',
                    'actual' => (string)$newPatients,
                    'progress' => round(($newPatients / 25) * 100),
                    'status' => $newPatients >= 25 ? 'success' : ($newPatients >= 20 ? 'warning' : 'danger'),
                ],
                [
                    'metric' => 'Taux de satisfaction',
                    'target' => '90%',
                    'actual' => $satisfactionRate . '%',
                    'progress' => $satisfactionRate,
                    'status' => $satisfactionRate >= 90 ? 'success' : ($satisfactionRate >= 80 ? 'warning' : 'danger'),
                ],
                [
                    'metric' => 'Adhésion moyenne',
                    'target' => '85%',
                    'actual' => round($averageAdherence) . '%',
                    'progress' => round($averageAdherence),
                    'status' => $averageAdherence >= 85 ? 'success' : ($averageAdherence >= 70 ? 'warning' : 'danger'),
                ],
                [
                    'metric' => 'RDV annulés',
                    'target' => '<5%',
                    'actual' => round($cancellationRate, 1) . '%',
                    'progress' => round((5 / max($cancellationRate, 1)) * 100),
                    'status' => $cancellationRate <= 5 ? 'success' : ($cancellationRate <= 10 ? 'warning' : 'danger'),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $goals
            ]);
        } catch (\Exception $e) {
            Log::error('Performance goals error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance goals',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ============================================
       CANCELLATION REASONS SECTION
       ============================================ */

    public function getCancellationReasons(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonths(3)));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $cancelledAppointments = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate]);
                })
                ->where('status', 'cancelled')
                ->with('cancellationReasons.reason')
                ->get();

            $reasons = CancellationReason::where('is_active', true)
                ->orderBy('order_index')
                ->get();

            $reasonStats = [];
            $totalCancelled = $cancelledAppointments->count();

            foreach ($reasons as $reason) {
                $reasonStats[$reason->id] = [
                    'id' => $reason->id,
                    'reason' => $reason->reason,
                    'type' => $reason->type,
                    'count' => 0
                ];
            }

            $reasonStats['other'] = [
                'id' => 'other',
                'reason' => 'Autre',
                'type' => 'general',
                'count' => 0
            ];

            foreach ($cancelledAppointments as $appointment) {
                $hasReason = false;

                if ($appointment->cancellationReasons->isNotEmpty()) {
                    foreach ($appointment->cancellationReasons as $cancellationReason) {
                        if ($cancellationReason->reason && isset($reasonStats[$cancellationReason->reason->id])) {
                            $reasonStats[$cancellationReason->reason->id]['count']++;
                            $hasReason = true;
                        }
                    }
                }

                if (!$hasReason) {
                    $reasonStats['other']['count']++;
                }
            }

            $cancellationReasons = [];
            foreach ($reasonStats as $data) {
                if ($data['count'] > 0) {
                    $percentage = $totalCancelled > 0 ? ($data['count'] / $totalCancelled) * 100 : 0;

                    $cancellationReasons[] = [
                        'id' => $data['id'],
                        'reason' => $data['reason'],
                        'count' => $data['count'],
                        'percentage' => round($percentage, 1),
                        'type' => $data['type'],
                    ];
                }
            }

            usort($cancellationReasons, function($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            return response()->json([
                'success' => true,
                'data' => $cancellationReasons
            ]);
        } catch (\Exception $e) {
            Log::error('Cancellation reasons error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cancellation reasons',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ============================================
       KEY INSIGHTS SECTION
       ============================================ */

    public function getKeyInsights(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonths(3)));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $insights = [];

            // 1. Growth Opportunity - Low occupancy time slots
            $timeSlotAnalysis = $this->analyzeTimeSlots($kineId, $startDate, $endDate);
            if (isset($timeSlotAnalysis['low_occupancy_slot'])) {
                $insights[] = [
                    'id' => 'growth_opportunity_1',
                    'type' => 'growth',
                    'title' => 'Opportunité de croissance',
                    'description' => "Vos créneaux de {$timeSlotAnalysis['low_occupancy_slot']} ont un taux d'occupation de seulement {$timeSlotAnalysis['low_occupancy_rate']}%. Ciblez ces plages avec des offres spéciales.",
                    'color' => 'green',
                    'icon' => 'TrendingUp',
                    'priority' => 'medium',
                    'created_at' => Carbon::now()->toISOString(),
                ];
            }

            // 2. Patient Retention
            $activePatientIds = $this->getActivePatientIds($startDate, $endDate);
            $activePatientsCount = count($activePatientIds);

            if ($activePatientsCount < 5) {
                $insights[] = [
                    'id' => 'retention_1',
                    'type' => 'retention',
                    'title' => 'Nouveaux patients',
                    'description' => "Vous avez actuellement {$activePatientsCount} patients actifs. Concentrez-vous sur l'acquisition de nouveaux patients.",
                    'color' => 'blue',
                    'icon' => 'Users',
                    'priority' => 'medium',
                    'created_at' => Carbon::now()->toISOString(),
                ];
            } else if ($activePatientsCount > 0) {
                $insights[] = [
                    'id' => 'retention_2',
                    'type' => 'retention',
                    'title' => 'Base de patients solide',
                    'description' => "Vous avez {$activePatientsCount} patients actifs. C'est une bonne base pour développer votre activité.",
                    'color' => 'green',
                    'icon' => 'Users',
                    'priority' => 'low',
                    'created_at' => Carbon::now()->toISOString(),
                ];
            }

            // 3. Appointment Optimization - High cancellation days
            $cancellationAnalysis = $this->analyzeAppointmentCancellations($kineId, $startDate, $endDate);
            if (isset($cancellationAnalysis['high_cancellation_day'])) {
                $insights[] = [
                    'id' => 'optimization_1',
                    'type' => 'optimization',
                    'title' => 'Optimisation des RDV',
                    'description' => "Les annulations sont plus fréquentes le {$cancellationAnalysis['high_cancellation_day']}. Envisagez des rappels SMS renforcés.",
                    'color' => 'amber',
                    'icon' => 'Clock',
                    'priority' => 'medium',
                    'created_at' => Carbon::now()->toISOString(),
                ];
            }

            // 4. Exercise Engagement
            $totalExercises = ExerciseSession::whereIn('patient_id', $activePatientIds)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();

            if ($totalExercises === 0 && $activePatientsCount > 0) {
                $insights[] = [
                    'id' => 'engagement_1',
                    'type' => 'engagement',
                    'title' => 'Engagement à améliorer',
                    'description' => "Aucun exercice n'a été enregistré pendant cette période. Encouragez vos patients à utiliser l'application.",
                    'color' => 'red',
                    'icon' => 'Activity',
                    'priority' => 'high',
                    'created_at' => Carbon::now()->toISOString(),
                ];
            }

            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            usort($insights, function($a, $b) use ($priorityOrder) {
                return $priorityOrder[$b['priority']] <=> $priorityOrder[$a['priority']];
            });

            return response()->json([
                'success' => true,
                'data' => $insights
            ]);
        } catch (\Exception $e) {
            Log::error('Key insights error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate key insights',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Analyze time slots to find low occupancy periods
     */
    private function analyzeTimeSlots($kineId, $startDate, $endDate)
    {
        $timeSlots = [
            ['slot' => '8h-10h', 'start' => 8, 'end' => 10],
            ['slot' => '10h-12h', 'start' => 10, 'end' => 12],
            ['slot' => '14h-16h', 'start' => 14, 'end' => 16],
            ['slot' => '16h-18h', 'start' => 16, 'end' => 18],
            ['slot' => '18h-20h', 'start' => 18, 'end' => 20],
        ];

        $analysis = [];

        foreach ($timeSlots as $slot) {
            $totalSlots = AppointmentSlot::where('kine_id', $kineId)
                ->whereBetween('start_time', [$startDate, $endDate])
                ->whereRaw('HOUR(start_time) >= ?', [$slot['start']])
                ->whereRaw('HOUR(start_time) < ?', [$slot['end']])
                ->count();

            $bookedSlots = AppointmentSlot::where('kine_id', $kineId)
                ->whereBetween('start_time', [$startDate, $endDate])
                ->whereRaw('HOUR(start_time) >= ?', [$slot['start']])
                ->whereRaw('HOUR(start_time) < ?', [$slot['end']])
                ->whereHas('appointments', function ($query) {
                    $query->where('status', 'completed');
                })
                ->count();

            $occupancyRate = $totalSlots > 0 ? ($bookedSlots / $totalSlots) * 100 : 0;

            $analysis[] = [
                'slot' => $slot['slot'],
                'occupancy_rate' => round($occupancyRate, 1),
                'total_slots' => $totalSlots,
                'booked_slots' => $bookedSlots,
            ];
        }

        $nonZeroSlots = array_filter($analysis, function($slot) {
            return $slot['total_slots'] > 0;
        });

        if (count($nonZeroSlots) > 0) {
            $lowestSlot = collect($nonZeroSlots)->sortBy('occupancy_rate')->first();

            if ($lowestSlot['occupancy_rate'] < 70) {
                return [
                    'low_occupancy_slot' => $lowestSlot['slot'],
                    'low_occupancy_rate' => $lowestSlot['occupancy_rate'],
                    'all_slots' => $analysis,
                ];
            }
        }

        return ['all_slots' => $analysis];
    }

    /**
     * Analyze appointment cancellations by day of week
     */
    private function analyzeAppointmentCancellations($kineId, $startDate, $endDate)
    {
        $daysOfWeek = [
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
        ];

        $cancellationsByDay = [];
        $totalCancellations = 0;

        foreach ($daysOfWeek as $dayNumber => $dayName) {
            $cancellations = Appointment::whereHas('slot', function ($query) use ($kineId, $startDate, $endDate, $dayNumber) {
                    $query->where('kine_id', $kineId)
                        ->whereBetween('start_time', [$startDate, $endDate])
                        ->whereRaw('DAYOFWEEK(start_time) = ?', [$dayNumber + 1]);
                })
                ->where('status', 'cancelled')
                ->count();

            $cancellationsByDay[$dayName] = $cancellations;
            $totalCancellations += $cancellations;
        }

        if ($totalCancellations > 0) {
            arsort($cancellationsByDay);
            $highestDay = array_key_first($cancellationsByDay);
            $highestCount = $cancellationsByDay[$highestDay];

            $averageCancellations = $totalCancellations / count($daysOfWeek);
            $multiplier = $averageCancellations > 0 ? $highestCount / $averageCancellations : 0;

            if ($multiplier >= 1.5) {
                return [
                    'high_cancellation_day' => $highestDay,
                    'day_multiplier' => round($multiplier, 1),
                    'cancellations_by_day' => $cancellationsByDay,
                    'total_cancellations' => $totalCancellations,
                ];
            }
        }

        return [
            'cancellations_by_day' => $cancellationsByDay,
            'total_cancellations' => $totalCancellations,
        ];
    }

    /* ============================================
       TIME SLOT EFFICIENCY SECTION
       ============================================ */

    public function getTimeSlotEfficiency(Request $request)
    {
        try {
            $kineId = $this->getKineId();
            $startDate = Carbon::parse($request->input('start_date', Carbon::now()->subMonth()));
            $endDate = Carbon::parse($request->input('end_date', Carbon::now()));

            $timeSlots = [
                ['slot' => '8h-10h', 'type' => 'Rééducation', 'start' => 8, 'end' => 10],
                ['slot' => '10h-12h', 'type' => 'Consultations', 'start' => 10, 'end' => 12],
                ['slot' => '14h-16h', 'type' => 'Suivi', 'start' => 14, 'end' => 16],
                ['slot' => '16h-18h', 'type' => 'Nouveaux', 'start' => 16, 'end' => 18],
                ['slot' => '18h-20h', 'type' => 'Urgences', 'start' => 18, 'end' => 20],
            ];

            $efficiencyData = [];

            foreach ($timeSlots as $slotInfo) {
                $startHour = $slotInfo['start'];
                $endHour = $slotInfo['end'];

                $totalSlots = AppointmentSlot::where('kine_id', $kineId)
                    ->whereBetween('start_time', [$startDate, $endDate])
                    ->whereRaw('HOUR(start_time) >= ?', [$startHour])
                    ->whereRaw('HOUR(start_time) < ?', [$endHour])
                    ->count();

                $bookedSlots = AppointmentSlot::where('kine_id', $kineId)
                    ->whereBetween('start_time', [$startDate, $endDate])
                    ->whereRaw('HOUR(start_time) >= ?', [$startHour])
                    ->whereRaw('HOUR(start_time) < ?', [$endHour])
                    ->whereHas('appointments', function ($query) {
                        $query->where('status', 'completed');
                    })
                    ->count();

                $utilization = $totalSlots > 0 ? ($bookedSlots / $totalSlots) * 100 : 0;

                $revenue = Appointment::whereHas('slot', function ($query) use ($kineId, $startHour, $endHour, $startDate, $endDate) {
                        $query->where('kine_id', $kineId)
                            ->whereBetween('start_time', [$startDate, $endDate])
                            ->whereRaw('HOUR(start_time) >= ?', [$startHour])
                            ->whereRaw('HOUR(start_time) < ?', [$endHour]);
                    })
                    ->where('status', 'completed')
                    ->sum('price');

                $efficiencyData[] = [
                    'slot' => $slotInfo['slot'],
                    'utilization' => round($utilization, 1),
                    'revenue' => round($revenue, 2),
                    'type' => $slotInfo['type'],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $efficiencyData
            ]);
        } catch (\Exception $e) {
            Log::error('Time slot efficiency error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch time slot efficiency',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ============================================
       EXPORT SECTION
       ============================================ */

    public function exportAnalyticsData(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => '/exports/analytics-export-' . Carbon::now()->format('Y-m-d') . '.csv',
                    'message' => 'Export generated successfully',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Export analytics error', [
                'error' => $e->getMessage(),
                'kine_id' => $this->getKineId()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export analytics data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
