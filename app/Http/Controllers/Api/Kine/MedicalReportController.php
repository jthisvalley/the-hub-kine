<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PatientProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MedicalReportController extends Controller
{
    /**
     * Generate PDF for medical report
     */
    public function generatePDF(Request $request, $patientId)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['patientProfile'])
            ->firstOrFail();


        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'format' => 'in:html,markdown',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = [
                'patient' => $patient,
                'content' => $request->content,
                'kine' => $user,
                'date' => now()->format('d/m/Y'),
                'time' => now()->format('H:i'),
            ];

            $pdf = PDF::loadView('pdf.medical-report', $data);

            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'defaultFont' => 'sans-serif',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

            $fileName = 'medical-report-' . $patientId . '-' . now()->timestamp . '.pdf';
            $filePath = 'medical_reports/' . $fileName;
            Storage::disk('public')->put($filePath, $pdf->output());

            return $pdf->download($fileName);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload medical image
     */
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'patient_id' => 'required|uuid|exists:users,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $patientId = $request->patient_id;

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $file = $request->file('image');
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('medical_images/' . $patientId, $fileName, 'public');

            $url = Storage::url($filePath);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'url' => url($url),
                'path' => $filePath,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error uploading image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update medical notes
     */
    public function updateMedicalNotes(Request $request, $patientId)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $patientId)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'medical_notes' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $patientProfile = PatientProfile::updateOrCreate(
                ['user_id' => $patientId],
                ['medical_notes' => $request->medical_notes]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medical notes updated successfully',
                'data' => $patientProfile
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating medical notes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
