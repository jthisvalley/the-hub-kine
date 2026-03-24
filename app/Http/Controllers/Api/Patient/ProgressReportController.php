<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientProgressReport;
use App\Models\ProgressReportRequest;
use App\Models\KinePatientAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProgressReportController extends Controller
{
    /**
     * Get patient's progress reports
     */
    public function index(Request $request)
    {
        $patient = Auth::user();

        // Fixed: Correct column names and added kineProfile relation
        $query = PatientProgressReport::with(['kine:id,first_name,last_name,email,avatar_url', 'kine.kineProfile:user_id,specialty'])
            ->where('patient_id', $patient->id)
            ->where('status', 'published')
            ->orderBy('report_date', 'desc');

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('report_date', [
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            ]);
        }

        // Filter by report type
        if ($request->has('type')) {
            $query->where('report_type', $request->type);
        }

        // Pagination
        $perPage = $request->per_page ?? 10;
        $reports = $query->paginate($perPage);

        $transformedReports = $reports->map(function ($report) {
            return [
                'id' => $report->id,
                'title' => $report->title,
                'summary' => $report->summary,
                'date' => $report->report_date->format('Y-m-d'),
                'type' => $report->report_type,
                'status' => $report->status,

                // Progress metrics
                'pain' => [
                    'current' => $report->pain_level_current,
                    'improvement' => $report->pain_improvement,
                    'trend' => $report->pain_improvement > 0 ? 'down' : ($report->pain_improvement < 0 ? 'up' : 'neutral'),
                ],
                'mobility' => [
                    'current' => $report->mobility_score_current,
                    'improvement' => $report->mobility_improvement,
                    'trend' => $report->mobility_improvement > 0 ? 'up' : 'down',
                ],
                'adherence' => $report->adherence_rate,
                'strength_improvement' => $report->strength_improvement,
                'flexibility_improvement' => $report->flexibility_improvement,

                // Session statistics
                'sessions' => [
                    'total' => $report->total_sessions,
                    'completed' => $report->completed_sessions,
                    'missed' => $report->missed_sessions,
                    'completion_rate' => $report->completion_rate,
                    'average_duration' => $report->average_session_duration,
                ],

                // Feedback
                'kine_observations' => $report->kine_observations,
                'kine_recommendations' => $report->kine_recommendations,
                'next_steps' => $report->next_steps,
                'patient_comments' => $report->patient_comments,
                'patient_satisfaction' => $report->patient_satisfaction,

                // Physiotherapist info - Fixed to use first_name and last_name
                'physiotherapist' => $report->kine ? [
                    'id' => $report->kine->id,
                    'name' => $report->kine->first_name . ' ' . $report->kine->last_name,
                    'first_name' => $report->kine->first_name,
                    'last_name' => $report->kine->last_name,
                    'avatar' => $report->kine->avatar_url,
                    'specialty' => $report->kine->kineProfile->specialty ?? null,
                ] : null,

                // Attachments
                'attachments' => $report->attachment_urls,

                // Metadata
                'created_at' => $report->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $report->updated_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformedReports,
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /**
     * Get a specific progress report
     */
    public function show($id)
    {
        $patient = Auth::user();

        // Fixed: Correct column names and added kineProfile relation
        $report = PatientProgressReport::with(['kine:id,first_name,last_name,email,phone,avatar_url', 'kine.kineProfile:user_id,specialty'])
            ->where('patient_id', $patient->id)
            ->where('id', $id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Progress report not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $report->id,
                'title' => $report->title,
                'summary' => $report->summary,
                'date' => $report->report_date->format('Y-m-d'),
                'type' => $report->report_type,
                'status' => $report->status,

                // Detailed progress metrics
                'pain' => [
                    'start' => $report->pain_level_start,
                    'current' => $report->pain_level_current,
                    'improvement' => $report->pain_improvement,
                    'improvement_percentage' => $report->pain_level_start > 0
                        ? ($report->pain_improvement / $report->pain_level_start) * 100
                        : 0,
                ],
                'mobility' => [
                    'start' => $report->mobility_score_start,
                    'current' => $report->mobility_score_current,
                    'improvement' => $report->mobility_improvement,
                    'improvement_percentage' => $report->mobility_score_start > 0
                        ? ($report->mobility_improvement / $report->mobility_score_start) * 100
                        : 0,
                ],
                'adherence' => $report->adherence_rate,
                'strength_improvement' => $report->strength_improvement,
                'flexibility_improvement' => $report->flexibility_improvement,

                // Session statistics
                'sessions' => [
                    'total' => $report->total_sessions,
                    'completed' => $report->completed_sessions,
                    'missed' => $report->missed_sessions,
                    'completion_rate' => $report->completion_rate,
                    'average_duration' => $report->average_session_duration,
                    'total_duration' => $report->total_sessions * $report->average_session_duration,
                ],

                // Detailed feedback
                'kine_observations' => $report->kine_observations,
                'kine_recommendations' => $report->kine_recommendations,
                'next_steps' => $report->next_steps,
                'patient_comments' => $report->patient_comments,
                'patient_satisfaction' => $report->patient_satisfaction,

                // Physiotherapist info - Fixed to use first_name and last_name
                'physiotherapist' => $report->kine ? [
                    'id' => $report->kine->id,
                    'name' => $report->kine->first_name . ' ' . $report->kine->last_name,
                    'first_name' => $report->kine->first_name,
                    'last_name' => $report->kine->last_name,
                    'email' => $report->kine->email,
                    'phone' => $report->kine->phone,
                    'avatar' => $report->kine->avatar_url,
                    'specialty' => $report->kine->kineProfile->specialty ?? null,
                ] : null,

                // Attachments
                'attachments' => $report->attachment_urls,

                // Timeline
                'created_at' => $report->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $report->updated_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Get patient's assigned kines
     */
    public function getPatientKines()
    {
        $patient = Auth::user();

        $assignments = KinePatientAssignment::with([
            'kine:id,first_name,last_name,email,phone,avatar_url',
            'kine.kineProfile:user_id,specialty,specialties'
        ])
        ->where('patient_id', $patient->id)
        ->whereHas('kine', function ($query) {
            $query->where('is_active', true);
        })
        ->get()
        ->map(function ($assignment) {
            $kine = $assignment->kine;

            return [
                'id' => $kine->id,
                'name' => $kine->first_name . ' ' . $kine->last_name,
                'first_name' => $kine->first_name,
                'last_name' => $kine->last_name,
                'email' => $kine->email,
                'phone' => $kine->phone,
                'avatar' => $kine->avatar_url,
                'specialty' => $kine->kineProfile->specialty ?? null,
                'qualifications' => $kine->kineProfile->specialties ?? null,
                'assigned_since' => $assignment->created_at->format('Y-m-d'),
                'assignment_id' => $assignment->id,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    /**
     * Request a new progress report
     */
    public function requestReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kine_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:2000',
            'urgency' => 'required|in:low,medium,high',
            'preferred_date' => 'nullable|date|after:today',
            'type' => 'required|in:routine_checkup,pain_increase,plateau,new_symptoms,other',
            'specific_concerns' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $patient = Auth::user();

        // Check if kine is assigned to patient
        $isAssigned = KinePatientAssignment::where('patient_id', $patient->id)
            ->where('kine_id', $request->kine_id)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'success' => false,
                'message' => 'This physiotherapist is not assigned to you'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $progressRequest = ProgressReportRequest::create([
                'patient_id' => $patient->id,
                'kine_id' => $request->kine_id,
                'reason' => $request->reason,
                'urgency' => $request->urgency,
                'preferred_date' => $request->preferred_date,
                'type' => $request->type,
                'specific_concerns' => $request->specific_concerns,
                'status' => 'pending',
            ]);

            // Log the request
            // activity()
            //     ->performedOn($progressRequest)
            //     ->causedBy($patient)
            //     ->withProperties([
            //         'kine_id' => $request->kine_id,
            //         'urgency' => $request->urgency,
            //         'type' => $request->type,
            //     ])
            //     ->log('requested_progress_report');

            // Send notification to kine (in real app, you'd use Laravel Notifications)
            // $kine = User::find($request->kine_id);
            // $kine->notify(new ProgressReportRequested($progressRequest));

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $progressRequest->id,
                    'kine_id' => $progressRequest->kine_id,
                    'reason' => $progressRequest->reason,
                    'urgency' => $progressRequest->urgency,
                    'type' => $progressRequest->type,
                    'status' => $progressRequest->status,
                    'requested_at' => $progressRequest->created_at->format('Y-m-d H:i:s'),
                    'estimated_response' => $this->getEstimatedResponseTime($request->urgency),
                ],
                'message' => 'Progress report request submitted successfully. Your physiotherapist will review it soon.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Failed to create progress report request: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit request. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get patient's progress report requests
     */
    public function getReportRequests(Request $request)
    {
        $patient = Auth::user();

        // Fixed: Correct column names and added kineProfile relation
        $query = ProgressReportRequest::with(['kine:id,first_name,last_name,avatar_url'])
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->per_page ?? 10;
        $requests = $query->paginate($perPage);

        $transformedRequests = $requests->map(function ($req) {
            return [
                'id' => $req->id,
                'kine_id' => $req->kine_id,
                'reason' => $req->reason,
                'urgency' => $req->urgency,
                'type' => $req->type,
                'specific_concerns' => $req->specific_concerns,
                'status' => $req->status,
                'kine_notes' => $req->kine_notes,
                'preferred_date' => $req->preferred_date?->format('Y-m-d'),
                'requested_at' => $req->created_at->format('Y-m-d H:i:s'),
                'reviewed_at' => $req->reviewed_at?->format('Y-m-d H:i:s'),
                'scheduled_at' => $req->scheduled_at?->format('Y-m-d H:i:s'),
                'completed_at' => $req->completed_at?->format('Y-m-d H:i:s'),
                'progress_report_id' => $req->progress_report_id,
                'days_since_request' => $req->days_since_request,
                'is_urgent' => $req->is_urgent,
                'kine' => $req->kine ? [
                    'name' => $req->kine->first_name . ' ' . $req->kine->last_name,
                    'first_name' => $req->kine->first_name,
                    'last_name' => $req->kine->last_name,
                    'avatar' => $req->kine->avatar_url,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformedRequests,
            'meta' => [
                'total_requests' => $requests->total(),
                'pending_requests' => ProgressReportRequest::where('patient_id', $patient->id)
                    ->where('status', 'pending')
                    ->count(),
                'urgent_requests' => ProgressReportRequest::where('patient_id', $patient->id)
                    ->where('urgency', 'high')
                    ->where('status', 'pending')
                    ->count(),
            ],
        ]);
    }

    /**
     * Download progress report as PDF
     */
    public function downloadReport($id)
    {
        $patient = Auth::user();

        $report = PatientProgressReport::where('patient_id', $patient->id)
            ->where('id', $id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Progress report not found'
            ], 404);
        }

        // In a real app, generate PDF here
        // For now, return a mock URL
        $pdfUrl = route('api.patient.progress-reports.download', [
            'id' => $report->id,
            'token' => hash('sha256', $report->id . env('APP_KEY'))
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $pdfUrl,
                'filename' => "rapport-progression-{$report->report_date->format('Y-m-d')}.pdf",
                'expires_at' => Carbon::now()->addHours(24)->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Get progress statistics
     */
    public function getStatistics()
    {
        $patient = Auth::user();

        $totalReports = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->count();

        // Assuming recent is defined as within last 30 days
        $recentReports = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $pendingRequests = ProgressReportRequest::where('patient_id', $patient->id)
            ->where('status', 'pending')
            ->count();

        $averageAdherence = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->avg('adherence_rate') ?? 0;

        $averagePainImprovement = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->avg('pain_improvement') ?? 0;

        $averageMobilityImprovement = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->avg('mobility_improvement') ?? 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_reports' => $totalReports,
                'recent_reports' => $recentReports,
                'pending_requests' => $pendingRequests,
                'average_adherence' => round($averageAdherence, 1),
                'average_pain_improvement' => round($averagePainImprovement, 1),
                'average_mobility_improvement' => round($averageMobilityImprovement, 1),
                'overall_progress' => $this->calculateOverallProgress($patient),
            ],
        ]);
    }

    /**
     * Helper methods
     */
    private function getEstimatedResponseTime($urgency)
    {
        return match($urgency) {
            'high' => '24-48 hours',
            'medium' => '3-5 business days',
            'low' => '5-7 business days',
            default => '3-5 business days',
        };
    }

    private function calculateOverallProgress($patient)
    {
        $recentReport = PatientProgressReport::where('patient_id', $patient->id)
            ->where('status', 'published')
            ->latest('report_date')
            ->first();

        if (!$recentReport) {
            return 0;
        }

        // Calculate weighted progress score
        $scores = [
            'adherence' => $recentReport->adherence_rate * 0.3,
            'pain_improvement' => max(0, $recentReport->pain_improvement * 10) * 0.3,
            'mobility_improvement' => max(0, $recentReport->mobility_improvement * 10) * 0.25,
            'strength_improvement' => max(0, $recentReport->strength_improvement) * 0.15,
        ];

        return min(100, round(array_sum($scores)));
    }
}
