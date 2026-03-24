<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\PatientDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PatientDocumentController extends Controller
{
    /**
     * Get all documents for a patient
     */
    public function index($patientId)
    {
        $user = auth()->user();
        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $documents = PatientDocument::where('patient_id', $patientId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $documents
        ]);
    }

    /**
     * Store a new document
     */
    public function store(Request $request, $patientId)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'type' => 'required|in:medical_report,prescription,xray,scan,other',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            \Log::error('Document validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $file = $request->file('file');

            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('patient_documents/' . $patientId, $fileName, 'public');

            $document = PatientDocument::create([
                'patient_id' => $patientId,
                'title' => $request->title,
                'type' => $request->type,
                'file_path' => $filePath,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_type' => $file->getMimeType(),
                'notes' => $request->notes,
                'uploaded_by' => $user->id,
            ]);

            DB::commit();

            \Log::info('Document created successfully:', ['document_id' => $document->id]);

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => $document
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Document upload error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error uploading document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a document
     */
    public function destroy($patientId, $documentId)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $document = PatientDocument::where('patient_id', $patientId)
            ->where('id', $documentId)
            ->firstOrFail();

        // Delete file from storage
        Storage::disk('public')->delete($document->file_path);

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully'
        ]);
    }

    /**
     * Download a document
     */
    public function download($patientId, $documentId)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $document = PatientDocument::where('patient_id', $patientId)
            ->where('id', $documentId)
            ->firstOrFail();

        $filePath = Storage::disk('public')->path($document->file_path);

        return response()->download($filePath, $document->file_name);
    }
}
