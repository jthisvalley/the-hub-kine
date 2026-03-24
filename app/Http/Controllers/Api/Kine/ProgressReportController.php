<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\PatientProgressReport;
use App\Models\ProgressReportRequest;
use App\Models\User;
use App\Models\ExerciseSession;
use App\Models\PatientGoal;
use App\Models\PatientProgramAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ProgressReportController extends Controller
{
    /**
     * Get all progress reports for a specific patient
     */
    public function index(Request $request, $patientId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $query = PatientProgressReport::with(['kine:id,first_name,last_name,avatar_url'])
            ->where('patient_id', $patientId)
            ->orderBy('report_date', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by report type (monthly, quarterly, on_demand, post_treatment)
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('report_type', $request->type);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('report_date', [
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date)
            ]);
        }

        // Paginate results
        $perPage = $request->per_page ?? 10;
        $reports = $query->paginate($perPage);

        // Transform data for frontend
        $transformedReports = $reports->map(function ($report) {
            return $this->transformReport($report);
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
     * Get a single progress report
     */
    public function show(Request $request, $patientId, $reportId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->firstOrFail();

        $report = PatientProgressReport::with(['kine:id,first_name,last_name,avatar_url,email,phone'])
            ->where('patient_id', $patientId)
            ->where('id', $reportId)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->transformReport($report, true),
        ]);
    }

    /**
     * Generate a new progress report
     */
    public function generate(Request $request, $patientId)
    {
        $kine = Auth::user();

        // Verify the patient belongs to this kine
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($kine) {
                $query->where('kine_id', $kine->id);
            })
            ->with(['patientProfile', 'exerciseSessions', 'goals', 'programAssignments.program'])
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'type' => 'required|in:monthly,quarterly,on_demand,post_treatment',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'summary_mode' => 'required|in:auto,manual',
            'custom_summary' => 'required_if:summary_mode,manual|string|nullable',
            'include_goals' => 'boolean',
            'include_exercises' => 'boolean',
            'include_charts' => 'boolean',
            'request_id' => 'nullable|exists:progress_report_requests,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Parse dates
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);

            // Calculate report data
            $reportData = $this->calculateReportData($patient, $startDate, $endDate);

            // Generate summary based on mode
            $summary = $request->summary_mode === 'auto'
                ? $this->generateSummary($reportData, $request->type, $startDate, $endDate)
                : $request->custom_summary;

            // Generate title if not provided
            $title = $request->title ?? $this->generateReportTitle($patient, $request->type, $endDate);

            // Create the report
            $report = PatientProgressReport::create([
                'patient_id' => $patientId,
                'kine_id' => $kine->id,
                'title' => $title,
                'summary' => $summary,
                'summary_mode' => $request->summary_mode,
                'report_date' => Carbon::now(),
                'period_start' => $startDate,
                'period_end' => $endDate,
                'report_type' => $request->type,
                'status' => 'draft',

                // Pain metrics
                'pain_level_start' => $reportData['pain']['start'],
                'pain_level_current' => $reportData['pain']['current'],
                'pain_improvement' => $reportData['pain']['improvement'],

                // Mobility metrics
                'mobility_score_start' => $reportData['mobility']['start'],
                'mobility_score_current' => $reportData['mobility']['current'],
                'mobility_improvement' => $reportData['mobility']['improvement'],

                // Other metrics
                'adherence_rate' => $reportData['adherence'],
                'strength_improvement' => $reportData['strength'],
                'flexibility_improvement' => $reportData['flexibility'],

                // Session statistics
                'total_sessions' => $reportData['sessions']['total'],
                'completed_sessions' => $reportData['sessions']['completed'],
                'missed_sessions' => $reportData['sessions']['missed'],
                'average_session_duration' => $reportData['sessions']['avg_duration'],

                // Goals
                'goals_achieved' => $reportData['goals']['achieved'],
                'goals_in_progress' => $reportData['goals']['in_progress'],
                'goals_failed' => $reportData['goals']['failed'],

                // Default empty fields
                'kine_observations' => null,
                'kine_recommendations' => null,
                'next_steps' => null,
                'patient_comments' => null,
                'patient_satisfaction' => null,
                'attachments' => null,
            ]);

            // If this was generated from a request, update the request
            if ($request->has('request_id')) {
                ProgressReportRequest::where('id', $request->request_id)
                    ->update([
                        'status' => 'completed',
                        'completed_at' => Carbon::now(),
                        'progress_report_id' => $report->id,
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformReport($report->load('kine')),
                'message' => 'Rapport de progression généré avec succès'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du rapport',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update a progress report
     */
    public function update(Request $request, $patientId, $reportId)
    {
        $kine = Auth::user();

        $report = PatientProgressReport::where('patient_id', $patientId)
            ->where('id', $reportId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'summary' => 'sometimes|string',
            'kine_observations' => 'nullable|string',
            'kine_recommendations' => 'nullable|string',
            'next_steps' => 'nullable|string',
            'patient_comments' => 'nullable|string',
            'patient_satisfaction' => 'nullable|integer|min:1|max:5',
            'status' => 'sometimes|in:draft,published,archived',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $updateData = $request->only([
                'title', 'summary', 'kine_observations', 'kine_recommendations',
                'next_steps', 'patient_comments', 'patient_satisfaction', 'status', 'attachments'
            ]);

            $report->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformReport($report->fresh('kine')),
                'message' => 'Rapport mis à jour avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du rapport',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Publish a report (make it visible to patient)
     */
    public function publish(Request $request, $patientId, $reportId)
    {
        $kine = Auth::user();

        $report = PatientProgressReport::where('patient_id', $patientId)
            ->where('id', $reportId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'notify_patient' => 'boolean',
            'message' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $report->update([
                'status' => 'published',
                'published_at' => Carbon::now(),
            ]);

            // If notify patient is true, you could send a notification here
            if ($request->notify_patient) {
                // $patient = User::find($patientId);
                // $patient->notify(new ReportPublishedNotification($report, $request->message));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $this->transformReport($report->fresh('kine')),
                'message' => 'Rapport publié avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la publication du rapport',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete a progress report
     */
    public function destroy($patientId, $reportId)
    {
        $kine = Auth::user();

        $report = PatientProgressReport::where('patient_id', $patientId)
            ->where('id', $reportId)
            ->firstOrFail();

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rapport supprimé avec succès'
        ]);
    }

    /**
     * Generate PDF for a progress report
     */
    public function generatePdf(Request $request, $patientId, $reportId)
    {
        try {
            $kine = Auth::user();

            $report = PatientProgressReport::with(['patient', 'kine'])
                ->where('patient_id', $patientId)
                ->where('id', $reportId)
                ->firstOrFail();

            $patient = User::with(['patientProfile'])
                ->where('id', $patientId)
                ->first();

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient non trouvé'
                ], 404);
            }

            // Generate a unique token for this download
            $token = md5($reportId . $patientId . now()->timestamp . $kine->id);

            // Store the token in cache with expiration (10 minutes)
            \Cache::put('pdf_token_' . $token, [
                'report_id' => $reportId,
                'patient_id' => $patientId,
                'kine_id' => $kine->id,
            ], now()->addMinutes(10));

            // Generate the download URL
            $url = route('kine.progress-reports.download', ['token' => $token]);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => $url,
                    'filename' => "rapport-progression-{$patient->first_name}-{$patient->last_name}-{$report->report_date->format('Y-m-d')}.pdf",
                    'expires_at' => now()->addMinutes(10)->toDateTimeString(),
                ],
                'message' => 'URL de téléchargement générée avec succès'
            ]);

        } catch (\Exception $e) {
            \Log::error('PDF URL Generation Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de l\'URL: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download PDF via token
     */
    public function downloadPdf($token)
    {
        try {
            // Verify token
            $data = Cache::get('pdf_token_' . $token);

            if (!$data) {
                abort(404, 'Lien de téléchargement expiré ou invalide');
            }

            $reportId = $data['report_id'];
            $patientId = $data['patient_id'];

            $report = PatientProgressReport::with(['patient', 'kine'])
                ->where('id', $reportId)
                ->where('patient_id', $patientId)
                ->firstOrFail();

            $patient = $report->patient;

            // Prepare data for PDF
            $data = [
                'report' => $report,
                'patient' => $patient,
                'kine' => $report->kine,
                'generated_at' => Carbon::now()->format('d/m/Y H:i'),
                'clinic_info' => [
                    'name' => 'Le Hub Kiné',
                    'address' => '123 Rue de la Santé, 75001 Paris',
                    'phone' => '01 23 45 67 89',
                    'email' => 'contact@lehubkine.fr',
                    'website' => 'www.lehubkine.fr',
                    'logo' => public_path('images/logo.png'),
                ],
            ];

            // Generate PDF
            $pdf = Pdf::loadView('pdf.progress-report', $data);

            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'defaultFont' => 'sans-serif',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
            ]);

            // Download the PDF
            return $pdf->download("rapport-progression-{$patient->first_name}-{$patient->last_name}-{$report->report_date->format('Y-m-d')}.pdf");

        } catch (\Exception $e) {
            \Log::error('PDF Download Error: ' . $e->getMessage());
            abort(500, 'Erreur lors du téléchargement du PDF');
        }
    }

    /**
     * Get statistics about reports
     */
    public function getStatistics($patientId)
    {
        $kine = Auth::user();

        $totalReports = PatientProgressReport::where('patient_id', $patientId)->count();
        $publishedReports = PatientProgressReport::where('patient_id', $patientId)
            ->where('status', 'published')
            ->count();
        $draftReports = PatientProgressReport::where('patient_id', $patientId)
            ->where('status', 'draft')
            ->count();

        $lastReport = PatientProgressReport::where('patient_id', $patientId)
            ->where('status', 'published')
            ->latest('report_date')
            ->first();

        $pendingRequests = ProgressReportRequest::where('patient_id', $patientId)
            ->where('status', 'pending')
            ->count();

        // Reports by type
        $reportsByType = [
            'monthly' => PatientProgressReport::where('patient_id', $patientId)
                ->where('report_type', 'monthly')->count(),
            'quarterly' => PatientProgressReport::where('patient_id', $patientId)
                ->where('report_type', 'quarterly')->count(),
            'on_demand' => PatientProgressReport::where('patient_id', $patientId)
                ->where('report_type', 'on_demand')->count(),
            'post_treatment' => PatientProgressReport::where('patient_id', $patientId)
                ->where('report_type', 'post_treatment')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'total_reports' => $totalReports,
                'published_reports' => $publishedReports,
                'draft_reports' => $draftReports,
                'last_report_date' => $lastReport?->report_date?->format('Y-m-d'),
                'last_report_title' => $lastReport?->title,
                'pending_requests' => $pendingRequests,
                'reports_by_type' => $reportsByType,
            ],
        ]);
    }

    /**
     * Get pending report requests
     */
    public function getReportRequests(Request $request, $patientId)
    {
        $kine = Auth::user();

        $query = ProgressReportRequest::with(['patient:id,first_name,last_name,avatar_url'])
            ->where('patient_id', $patientId)
            ->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $perPage = $request->per_page ?? 10;
        $requests = $query->paginate($perPage);

        $transformedRequests = $requests->map(function ($req) {
            return [
                'id' => $req->id,
                'patient_id' => $req->patient_id,
                'reason' => $req->reason,
                'urgency' => $req->urgency,
                'type' => $req->type,
                'specific_concerns' => $req->specific_concerns,
                'status' => $req->status,
                'kine_notes' => $req->kine_notes,
                'preferred_date' => $req->preferred_date?->format('Y-m-d'),
                'requested_at' => $req->created_at->format('Y-m-d H:i:s'),
                'reviewed_at' => $req->reviewed_at?->format('Y-m-d H:i:s'),
                'completed_at' => $req->completed_at?->format('Y-m-d H:i:s'),
                'progress_report_id' => $req->progress_report_id,
                'days_since_request' => $req->created_at->diffInDays(now()),
                'is_urgent' => $req->urgency === 'high',
                'patient' => $req->patient ? [
                    'name' => $req->patient->first_name . ' ' . $req->patient->last_name,
                    'avatar' => $req->patient->avatar_url,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformedRequests,
            'meta' => [
                'total' => $requests->total(),
                'pending' => $requests->where('status', 'pending')->count(),
                'urgent' => $requests->where('urgency', 'high')->where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Update report request status
     */
    public function updateReportRequest(Request $request, $requestId)
    {
        $kine = Auth::user();

        $reportRequest = ProgressReportRequest::where('id', $requestId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,reviewed,in_progress,completed,cancelled',
            'kine_notes' => 'nullable|string',
            'scheduled_date' => 'nullable|date|after:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $updateData = [
                'status' => $request->status,
                'kine_notes' => $request->kine_notes,
            ];

            if ($request->status === 'reviewed') {
                $updateData['reviewed_at'] = Carbon::now();
            }

            if ($request->has('scheduled_date')) {
                $updateData['scheduled_at'] = Carbon::parse($request->scheduled_date);
            }

            $reportRequest->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $reportRequest,
                'message' => 'Demande mise à jour avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Generate automatic monthly reports (to be called by cron)
     */
    public function generateMonthlyReports()
    {
        $kines = User::where('role', 'kine')->where('is_active', true)->get();

        foreach ($kines as $kine) {
            $patients = User::where('role', 'patient')
                ->whereHas('assignedKine', function ($query) use ($kine) {
                    $query->where('kine_id', $kine->id);
                })
                ->get();

            foreach ($patients as $patient) {
                // Check if we already generated a report this month
                $existingReport = PatientProgressReport::where('patient_id', $patient->id)
                    ->whereMonth('report_date', Carbon::now()->month)
                    ->whereYear('report_date', Carbon::now()->year)
                    ->exists();

                if (!$existingReport) {
                    // Generate automatic monthly report
                    $startDate = Carbon::now()->subMonth();
                    $endDate = Carbon::now();

                    $reportData = $this->calculateReportData($patient, $startDate, $endDate);
                    $summary = $this->generateSummary($reportData, 'monthly', $startDate, $endDate);

                    PatientProgressReport::create([
                        'patient_id' => $patient->id,
                        'kine_id' => $kine->id,
                        'title' => "Rapport mensuel - " . Carbon::now()->format('F Y'),
                        'summary' => $summary,
                        'summary_mode' => 'auto',
                        'report_date' => Carbon::now(),
                        'period_start' => $startDate,
                        'period_end' => $endDate,
                        'report_type' => 'monthly',
                        'status' => 'draft',

                        'pain_level_start' => $reportData['pain']['start'],
                        'pain_level_current' => $reportData['pain']['current'],
                        'pain_improvement' => $reportData['pain']['improvement'],

                        'mobility_score_start' => $reportData['mobility']['start'],
                        'mobility_score_current' => $reportData['mobility']['current'],
                        'mobility_improvement' => $reportData['mobility']['improvement'],

                        'adherence_rate' => $reportData['adherence'],
                        'strength_improvement' => $reportData['strength'],
                        'flexibility_improvement' => $reportData['flexibility'],

                        'total_sessions' => $reportData['sessions']['total'],
                        'completed_sessions' => $reportData['sessions']['completed'],
                        'missed_sessions' => $reportData['sessions']['missed'],
                        'average_session_duration' => $reportData['sessions']['avg_duration'],

                        'goals_achieved' => $reportData['goals']['achieved'],
                        'goals_in_progress' => $reportData['goals']['in_progress'],
                        'goals_failed' => $reportData['goals']['failed'],
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Rapports mensuels générés avec succès'
        ]);
    }

    /**
     * Calculate report data from patient sessions and goals
     */
    private function calculateReportData($patient, $startDate, $endDate)
    {
        // Get sessions in date range
        $sessions = ExerciseSession::where('patient_id', $patient->id)
            ->whereBetween('session_date', [$startDate, $endDate])
            ->get();

        // Calculate pain metrics
        $painSessions = $sessions->where('pain_level', '>', 0)->sortBy('session_date');
        $painStart = $painSessions->isNotEmpty() ? $painSessions->first()->pain_level : 0;
        $painCurrent = $painSessions->isNotEmpty() ? $painSessions->last()->pain_level : 0;
        $painImprovement = $painStart - $painCurrent;

        // Calculate mobility metrics (if you have mobility scores)
        // This is a placeholder - you should calculate from actual data
        $mobilityStart = 50;
        $mobilityCurrent = 65;
        $mobilityImprovement = $mobilityCurrent - $mobilityStart;

        // Calculate adherence
        $totalSessions = $sessions->count();
        $completedSessions = $sessions->where('status', 'completed')->count();
        $adherence = $totalSessions > 0 ? ($completedSessions / $totalSessions) * 100 : 0;

        // Get goals relevant to this period
        $goals = PatientGoal::where('patient_id', $patient->id)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate])
                    ->orWhereBetween('updated_at', [$startDate, $endDate])
                    ->orWhereBetween('deadline', [$startDate, $endDate]);
            })
            ->get();

        return [
            'pain' => [
                'start' => round($painStart, 1),
                'current' => round($painCurrent, 1),
                'improvement' => round($painImprovement, 1),
            ],
            'mobility' => [
                'start' => $mobilityStart,
                'current' => $mobilityCurrent,
                'improvement' => $mobilityImprovement,
            ],
            'adherence' => round($adherence, 1),
            'strength' => 15, // Placeholder - calculate from actual data
            'flexibility' => 10, // Placeholder - calculate from actual data
            'sessions' => [
                'total' => $totalSessions,
                'completed' => $completedSessions,
                'missed' => $totalSessions - $completedSessions,
                'avg_duration' => round($sessions->avg('duration_minutes') ?? 0),
            ],
            'goals' => [
                'achieved' => $goals->where('status', 'completed')->count(),
                'in_progress' => $goals->where('status', 'in_progress')->count(),
                'failed' => $goals->where('status', 'failed')->count(),
            ],
        ];
    }

    /**
     * Generate summary from report data
     */
    private function generateSummary($data, $type, $startDate, $endDate)
    {
        $periodDesc = match($type) {
            'monthly' => 'mensuelle',
            'quarterly' => 'trimestrielle',
            'post_treatment' => 'de fin de traitement',
            default => 'personnalisée',
        };

        $startFormatted = $startDate->format('d/m/Y');
        $endFormatted = $endDate->format('d/m/Y');

        $summary = "**Rapport de progression {$periodDesc}**\n\n";
        $summary .= "Période analysée : du {$startFormatted} au {$endFormatted}\n\n";

        $summary .= "## Résumé général\n";
        $summary .= "Sur cette période, le patient a complété {$data['sessions']['completed']} séances sur {$data['sessions']['total']} ";
        $summary .= "soit un taux d'adhérence de {$data['adherence']}%. ";

        // Pain evolution
        if ($data['pain']['improvement'] > 0) {
            $summary .= "La douleur a diminué de {$data['pain']['improvement']} points ";
        } elseif ($data['pain']['improvement'] < 0) {
            $summary .= "La douleur a augmenté de " . abs($data['pain']['improvement']) . " points ";
        } else {
            $summary .= "La douleur est restée stable ";
        }

        // Mobility evolution
        if ($data['mobility']['improvement'] > 0) {
            $summary .= "et la mobilité s'est améliorée de {$data['mobility']['improvement']}%.";
        } elseif ($data['mobility']['improvement'] < 0) {
            $summary .= "et la mobilité a diminué de " . abs($data['mobility']['improvement']) . "%.";
        } else {
            $summary .= "et la mobilité est restée stable.";
        }

        // Goals
        if ($data['goals']['achieved'] > 0) {
            $summary .= " {$data['goals']['achieved']} objectif(s) ont été atteints.";
        }
        if ($data['goals']['in_progress'] > 0) {
            $summary .= " {$data['goals']['in_progress']} objectif(s) sont en cours.";
        }

        // Detailed statistics
        $summary .= "\n\n## Statistiques détaillées\n";
        $summary .= "- **Douleur** : début {$data['pain']['start']}/10 → fin {$data['pain']['current']}/10\n";
        $summary .= "- **Mobilité** : début {$data['mobility']['start']}% → fin {$data['mobility']['current']}%\n";
        $summary .= "- **Séances complétées** : {$data['sessions']['completed']}/{$data['sessions']['total']}\n";
        $summary .= "- **Durée moyenne des séances** : {$data['sessions']['avg_duration']} minutes\n";

        if ($type === 'post_treatment') {
            $summary .= "\n## Conclusion\n";
            $summary .= "Ce rapport marque la fin du traitement. Le patient a démontré une progression ";
            $summary .= ($data['pain']['improvement'] > 0 || $data['mobility']['improvement'] > 0)
                ? "significative" : "modérée";
            $summary .= " tout au long de sa rééducation. Les objectifs fixés ont été ";
            $summary .= $data['goals']['achieved'] > 0 ? "partiellement ou totalement atteints" : "en cours de réalisation";
            $summary .= ".\n";
        }

        return $summary;
    }

    /**
     * Generate report title
     */
    private function generateReportTitle($patient, $type, $date)
    {
        $patientName = $patient->first_name . ' ' . $patient->last_name;

        switch ($type) {
            case 'monthly':
                return "Rapport mensuel - {$patientName} - " . $date->format('F Y');
            case 'quarterly':
                return "Rapport trimestriel - {$patientName} - " . $date->format('Y');
            case 'post_treatment':
                return "Bilan de fin de traitement - {$patientName} - " . $date->format('d/m/Y');
            case 'on_demand':
                return "Rapport personnalisé - {$patientName} - " . $date->format('d/m/Y');
            default:
                return "Rapport de progression - {$patientName} - " . $date->format('d/m/Y');
        }
    }

    /**
     * Transform report for API response
     */
    private function transformReport($report, $detailed = false)
    {
        $periodStart = $report->period_start ? $report->period_start->format('Y-m-d') : null;
        $periodEnd = $report->period_end ? $report->period_end->format('Y-m-d') : null;
        $reportDate = $report->report_date ? $report->report_date->format('Y-m-d') : now()->format('Y-m-d');
        $createdAt = $report->created_at ? $report->created_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
        $updatedAt = $report->updated_at ? $report->updated_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');

        $base = [
            'id' => $report->id,
            'title' => $report->title,
            'summary' => $report->summary,
            'summary_mode' => $report->summary_mode,
            'date' => $reportDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'type' => $report->report_type,
            'status' => $report->status,

            'pain' => [
                'start' => (float) ($report->pain_level_start ?? 0),
                'current' => (float) ($report->pain_level_current ?? 0),
                'improvement' => (float) ($report->pain_improvement ?? 0),
                'trend' => $this->calculatePainTrend($report->pain_improvement ?? 0),
            ],
            'mobility' => [
                'start' => (float) ($report->mobility_score_start ?? 0),
                'current' => (float) ($report->mobility_score_current ?? 0),
                'improvement' => (float) ($report->mobility_improvement ?? 0),
                'trend' => ($report->mobility_improvement ?? 0) > 0 ? 'up' : 'down',
            ],
            'adherence' => (float) ($report->adherence_rate ?? 0),
            'strength_improvement' => (float) ($report->strength_improvement ?? 0),
            'flexibility_improvement' => (float) ($report->flexibility_improvement ?? 0),

            'sessions' => [
                'total' => (int) ($report->total_sessions ?? 0),
                'completed' => (int) ($report->completed_sessions ?? 0),
                'missed' => (int) ($report->missed_sessions ?? 0),
                'completion_rate' => $this->calculateCompletionRate($report),
                'average_duration' => (int) ($report->average_session_duration ?? 0),
            ],

            'goals' => [
                'achieved' => (int) ($report->goals_achieved ?? 0),
                'in_progress' => (int) ($report->goals_in_progress ?? 0),
                'failed' => (int) ($report->goals_failed ?? 0),
            ],

            'kine_observations' => $report->kine_observations,
            'kine_recommendations' => $report->kine_recommendations,
            'next_steps' => $report->next_steps,
            'patient_comments' => $report->patient_comments,
            'patient_satisfaction' => $report->patient_satisfaction,

            'attachments' => $report->attachments ? $report->attachments : [],

            'created_at' => $createdAt,
            'updated_at' => $updatedAt,

            'kine' => $report->kine ? [
                'id' => $report->kine->id,
                'name' => trim($report->kine->first_name . ' ' . $report->kine->last_name),
                'avatar' => $report->kine->avatar_url,
            ] : null,
        ];

        if ($detailed) {
            $base['can_edit'] = $report->status === 'draft';
            $base['can_publish'] = $report->status === 'draft';
        }

        return $base;
    }

    /**
     * Calculate pain trend
     */
    private function calculatePainTrend($improvement)
    {
        if ($improvement > 0) return 'down';
        if ($improvement < 0) return 'up';
        return 'neutral';
    }

    /**
     * Calculate completion rate
     */
    private function calculateCompletionRate($report)
    {
        if (($report->total_sessions ?? 0) > 0) {
            return round(($report->completed_sessions / $report->total_sessions) * 100, 1);
        }
        return 0;
    }
}
