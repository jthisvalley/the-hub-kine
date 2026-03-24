<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentReport;
use App\Models\ReportDocument;
use App\Models\Notification;
use App\Enums\NotificationPriority;
use App\Events\NewNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AppointmentReportController extends Controller
{
    /**
     * Add a report to a completed appointment
     */
    public function store(Request $request, $id)
    {
        $user = Auth::user();

        Log::info('File upload attempt', [
            'files' => $_FILES,
            'post' => $_POST,
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'method' => $request->method(),
        ]);

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'patient'])
            ->findOrFail($id);

        if ($appointment->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous terminés peuvent avoir un rapport',
            ], 400);
        }

        if ($appointment->hasReport()) {
            return response()->json([
                'success' => false,
                'message' => 'Un rapport existe déjà pour ce rendez-vous',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed', [
                'errors' => $validator->errors()->toArray(),
                'files' => $request->file('documents') ? 'has files' : 'no files',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation échouée',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $report = AppointmentReport::create([
                'appointment_id' => $appointment->id,
                'notes' => $request->notes,
            ]);

            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $key => $file) {
                    if (!$file->isValid()) {
                        continue;
                    }

                    $originalName = $file->getClientOriginalName();
                    $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($originalName, PATHINFO_FILENAME));
                    $extension = $file->getClientOriginalExtension();
                    $filename = time() . '_' . $key . '_' . $cleanName . '.' . $extension;

                    $path = $file->storeAs('reports/' . $appointment->id, $filename, 'public');
                    $fullUrl = url('/public/storage/' . $path);

                    ReportDocument::create([
                        'report_id' => $report->id,
                        'filename' => $originalName,
                        'file_path' => $fullUrl,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);

                    Log::info('File stored successfully', [
                        'original' => $originalName,
                        'stored_as' => $filename,
                        'path' => $path,
                        'full_url' => $fullUrl,
                    ]);
                }
            }

            // Send notification to patient about the report
            if ($appointment->patient) {
                $this->createReportNotification(
                    $appointment->patient_id,
                    'report.created',
                    'Rapport de séance disponible',
                    "Votre kinésithérapeute a ajouté un rapport pour la séance du " . $appointment->slot->start_time->format('d/m/Y H:i'),
                    NotificationPriority::MEDIUM,
                    [
                        'appointment_id' => $appointment->id,
                        'report_id' => $report->id,
                        'appointment_date' => $appointment->slot->start_time->toISOString(),
                        'has_documents' => $request->hasFile('documents'),
                    ],
                    "/patient/appointments/{$appointment->id}/report"
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rapport ajouté avec succès',
                'data' => $this->formatReport($report->load('documents')),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error adding report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout du rapport',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing report
     */
    public function update(Request $request, $id, $reportId)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['slot', 'patient'])
            ->findOrFail($id);

        if ($appointment->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les rendez-vous terminés peuvent avoir un rapport',
            ], 400);
        }

        $report = AppointmentReport::where('appointment_id', $appointment->id)
            ->with('documents')
            ->findOrFail($reportId);

        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|required|string',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            if ($request->has('notes')) {
                $report->notes = $request->notes;
                $report->save();
            }

            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $key => $file) {
                    if (!$file->isValid()) {
                        continue;
                    }

                    $originalName = $file->getClientOriginalName();
                    $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($originalName, PATHINFO_FILENAME));
                    $extension = $file->getClientOriginalExtension();
                    $filename = time() . '_' . $key . '_' . $cleanName . '.' . $extension;

                    $path = $file->storeAs('reports/' . $appointment->id, $filename, 'public');
                    $fullUrl = url('/public/storage/' . $path);

                    ReportDocument::create([
                        'report_id' => $report->id,
                        'filename' => $originalName,
                        'file_path' => $fullUrl,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            // Send notification to patient about the updated report
            if ($appointment->patient) {
                $this->createReportNotification(
                    $appointment->patient_id,
                    'report.updated',
                    'Rapport de séance mis à jour',
                    "Votre kinésithérapeute a mis à jour le rapport pour la séance du " . $appointment->slot->start_time->format('d/m/Y H:i'),
                    NotificationPriority::LOW,
                    [
                        'appointment_id' => $appointment->id,
                        'report_id' => $report->id,
                        'appointment_date' => $appointment->slot->start_time->toISOString(),
                    ],
                    "/patient/appointments/{$appointment->id}/report"
                );
            }

            DB::commit();

            $report->load('documents');

            return response()->json([
                'success' => true,
                'message' => 'Rapport mis à jour avec succès',
                'data' => $this->formatReport($report),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error updating report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du rapport',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a document from a report
     */
    public function deleteDocument(Request $request, $id, $reportId, $documentId)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->findOrFail($id);

        $report = AppointmentReport::where('appointment_id', $appointment->id)
            ->findOrFail($reportId);

        $document = ReportDocument::where('report_id', $report->id)
            ->findOrFail($documentId);

        DB::beginTransaction();

        try {
            $urlParts = parse_url($document->file_path);
            $path = ltrim($urlParts['path'], '/');
            $relativePath = str_replace('public/storage/', '', $path);

            if (Storage::disk('public')->exists($relativePath)) {
                Storage::disk('public')->delete($relativePath);
            }

            $document->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document supprimé avec succès',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error deleting document', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get appointment report
     */
    public function show($id)
    {
        $user = Auth::user();

        $appointment = Appointment::whereHas('slot', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['report.documents'])
            ->findOrFail($id);

        if (!$appointment->hasReport()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun rapport trouvé pour ce rendez-vous',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($appointment->report),
        ]);
    }

    /**
     * Format report for response
     */
    private function formatReport($report)
    {
        return [
            'id' => $report->id,
            'appointment_id' => $report->appointment_id,
            'notes' => $report->notes,
            'documents' => $report->documents->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'filename' => $doc->filename,
                    'file_path' => $doc->file_path,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'created_at' => $doc->created_at->toISOString(),
                ];
            }),
            'created_at' => $report->created_at->toISOString(),
            'updated_at' => $report->updated_at->toISOString(),
        ];
    }

    /**
     * Helper method to create report notifications
     */
    private function createReportNotification($userId, $type, $title, $message, $priority, $metadata = null, $actionUrl = null)
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'metadata' => $metadata,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);

        broadcast(new NewNotification($notification));

        return $notification;
    }
}
