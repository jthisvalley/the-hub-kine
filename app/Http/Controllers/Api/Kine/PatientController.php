<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\PatientProfile;
use App\Models\KinePatientAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\PatientAccountCreated;
use App\Models\ExerciseSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    /**
     * Display a listing of patients for the current kine.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', '');
        $status = $request->get('status', 'all');

        $query = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['patientProfile', 'loyaltyPoints']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('patientProfile', function ($q) use ($search) {
                      $q->where('medical_notes', 'like', "%{$search}%");
                  });
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $patients = $query->paginate($perPage);

        return PatientResource::collection($patients);
    }

    public function getPatients(Request $request)
    {
        $user = auth()->user();


        $query = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with([
                'patientProfile',
                'loyaltyPoints',
                'appointments' => function ($query) {
                    $query->with(['slot'])
                        ->where('status', 'scheduled')
                        ->whereHas('slot', function ($q) {
                            $q->where('start_time', '>', now());
                        })
                        ->join('appointment_slots', 'appointments.slot_id', '=', 'appointment_slots.id')
                        ->orderBy('appointment_slots.start_time', 'asc')
                        ->select('appointments.*')
                        ->take(1);
                },
                'appointmentsWithSlot' => function ($query) {
                    $query->where('status', 'completed')
                        ->join('appointment_slots', 'appointments.slot_id', '=', 'appointment_slots.id')
                        ->orderBy('appointment_slots.start_time', 'desc')
                        ->select('appointments.*')
                        ->take(1);
                },
                'productRecommendations.product' => function ($query) {
                    $query->with(['category', 'subcategory']);
                }
            ]);

        if ($request->include === 'statistics') {
            $query->with(['exerciseSessions', 'patientGoals']);
        }

        $patients = $query->get();

        return PatientResource::collection($patients);
    }

    /**
     * Store a newly created patient with account creation.
     */
    public function store(StorePatientRequest $request)
    {
        DB::beginTransaction();

        try {
            $kine = auth()->user();
            $password = Str::random(10);

            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($password),
                'role' => 'patient',
                'phone' => $request->phone,
                'date_of_birth' => $request->date_of_birth,
                'address' => $request->address,
                'city' => $request->city,
                'country' => $request->country,
                'is_active' => false,
            ]);

            $patientProfile = PatientProfile::create([
                'user_id' => $user->id,
                'birth_date' => $request->date_of_birth,
                'gender' => $request->gender,
                'height_cm' => $request->height_cm,
                'weight_kg' => $request->weight_kg,
                'medical_notes' => $request->medical_notes,
                'preferred_language' => $request->preferred_language ?? 'fr',
                'emergency_contact_name' => $request->emergency_contact_name,
                'emergency_contact_phone' => $request->emergency_contact_phone,
                'notification_preferences' => json_encode([
                    'email' => $request->email_notifications ?? true,
                    'push' => $request->push_notifications ?? true,
                    'sms' => $request->sms_notifications ?? false,
                ]),
                'preferred_contact_method' => $request->preferred_contact_method ?? 'email',
            ]);

            KinePatientAssignment::create([
                'kine_id' => $kine->id,
                'patient_id' => $user->id,
            ]);

            if ($request->has('pathologies') && is_array($request->pathologies)) {
                $pathologiesData = [];
                foreach ($request->pathologies as $pathology) {
                    $pathologiesData[$pathology['id']] = [
                        'id' => Str::uuid(),
                        'diagnosed_date' => $pathology['diagnosed_date'] ?? null,
                        'notes' => $pathology['notes'] ?? null,
                        'is_active' => true,
                    ];
                }

                $patientProfile->pathologies()->sync($pathologiesData);
            }

            if ($request->filled('email')) {
                try {
                    Mail::to($user->email)->send(new PatientAccountCreated($user, $password));
                    $emailSent = true;
                } catch (\Exception $e) {
                    \Log::warning('Failed to send welcome email to patient', [
                        'patient_id' => $user->id,
                        'email' => $user->email,
                        'error' => $e->getMessage()
                    ]);
                    $emailSent = false;
                }
            } else {
                $emailSent = false;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $emailSent
                    ? 'Patient créé avec succès. Un email a été envoyé avec les informations de connexion.'
                    : 'Patient créé avec succès. (Aucun email envoyé car l\'adresse email n\'a pas été fournie)',
                'data' => new PatientResource($user->load('patientProfile.pathologies')),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Patient creation error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du patient',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified patient.
     */
    public function show(Request $request, $id)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $id)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with([
                'patientProfile',
                'loyaltyPoints',
                'patientDocuments',
                'goals' => function ($query) {
                    $query->with('kine')->latest();
                },
                'exerciseSessions' => function ($query) {
                    $query->with('exercise')->latest();
                },
                'programAssignments.program',
                'dailyCheckins' => function ($query) {
                    $query->latest()->limit(7);
                },
                'appointments' => function ($query) {
                    $query->where('status', 'scheduled')->latest();
                },
            ])
            ->firstOrFail();

        return new PatientResource($patient);
    }

    /**
     * Update the specified patient.
     */
    public function update(UpdatePatientRequest $request, $id)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $id)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $patient->update([
                'first_name' => $request->first_name ?? $patient->first_name,
                'last_name' => $request->last_name ?? $patient->last_name,
                'email' => $request->has('email') ? $request->email : $patient->email,
                'phone' => $request->phone ?? $patient->phone,
                'date_of_birth' => $request->date_of_birth ?? $patient->date_of_birth,
                'address' => $request->address ?? $patient->address,
                'city' => $request->city ?? $patient->city,
                'postal_code' => $request->postal_code ?? $patient->postal_code,
                'country' => $request->country ?? $patient->country,
                'is_active' => $request->has('is_active') ? $request->is_active : $patient->is_active,
            ]);

            if ($patient->patientProfile) {
                $profileData = [];
                if ($request->has('gender')) $profileData['gender'] = $request->gender;
                if ($request->has('height_cm')) $profileData['height_cm'] = $request->height_cm;
                if ($request->has('weight_kg')) $profileData['weight_kg'] = $request->weight_kg;
                if ($request->has('medical_notes')) $profileData['medical_notes'] = $request->medical_notes;
                if ($request->has('preferred_language')) $profileData['preferred_language'] = $request->preferred_language;
                if ($request->has('emergency_contact_name')) $profileData['emergency_contact_name'] = $request->emergency_contact_name;
                if ($request->has('emergency_contact_phone')) $profileData['emergency_contact_phone'] = $request->emergency_contact_phone;
                if ($request->has('preferred_contact_method')) $profileData['preferred_contact_method'] = $request->preferred_contact_method;

                // Update notification preferences if provided
                if ($request->has('email_notifications') || $request->has('push_notifications') || $request->has('sms_notifications')) {
                    $currentPrefs = json_decode($patient->patientProfile->notification_preferences, true) ?? [];
                    $profileData['notification_preferences'] = json_encode([
                        'email' => $request->email_notifications ?? $currentPrefs['email'] ?? true,
                        'push' => $request->push_notifications ?? $currentPrefs['push'] ?? true,
                        'sms' => $request->sms_notifications ?? $currentPrefs['sms'] ?? false,
                    ]);
                }

                $patient->patientProfile->update($profileData);
            }

            if ($request->has('pathologies')) {
                $syncData = [];
                foreach ($request->pathologies as $pathology) {
                    $syncData[$pathology['id']] = [
                        'diagnosed_date' => $pathology['diagnosed_date'] ?? null,
                        'notes' => $pathology['notes'] ?? null,
                        'is_active' => true,
                    ];
                }
                $patient->patientProfile->pathologies()->sync($syncData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Patient mis à jour avec succès',
                'data' => new PatientResource($patient->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du patient',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Remove the specified patient.
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $id)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $patient->update(['is_active' => false]);
        $patient->delete();

        return response()->json([
            'success' => true,
            'message' => 'Patient supprimé avec succès',
        ]);
    }

    /**
     * Toggle the status.
     */

    public function toggleStatus($id)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $id)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $patient->update([
            'is_active' => !$patient->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Patient status updated',
            'data' => [
                'is_active' => $patient->is_active
            ]
        ]);
    }

    public function getActivePatientsWithPrograms(Request $request)
    {
        $user = auth()->user();

        $patients = User::where('role', 'patient')
            ->where('is_active', true)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->whereHas('programAssignments')
            ->with([
                'patientProfile',
                'loyaltyPoints',
                'programAssignments' => function ($query) {
                    $query->with('program.exercises')->latest();
                }
            ])
            ->withCount([
                'programAssignments as total_programs',
                'programAssignments as active_programs' => function ($query) {
                    $query->where('status', 'active');
                }
            ])
            ->get();

        $activePatientsWithPrograms = [];

        foreach ($patients as $patient) {
            // Get ALL patient sessions
            $patientSessions = ExerciseSession::where('patient_id', $patient->id)->get();
            $totalSessions = $patientSessions->count();
            $completedSessions = $patientSessions->where('status', 'completed')->count();
            $adherenceRate = $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0;

            // Get current active program
            $currentProgram = null;
            $latestActiveAssignment = $patient->programAssignments
                ->where('status', 'active')
                ->first();

            if ($latestActiveAssignment && $latestActiveAssignment->program) {
                $currentProgram = [
                    'id' => $latestActiveAssignment->program->id,
                    'name' => $latestActiveAssignment->program->name,
                ];
            }

            // Build programs array from programAssignments
            $programs = [];
            foreach ($patient->programAssignments as $assignment) {
                if ($assignment->program) {
                    // Get ALL sessions for this program assignment
                    $programSessions = ExerciseSession::where('program_assignment_id', $assignment->id)->get();

                    // Get total exercises in the program
                    $totalExercisesInProgram = $assignment->program->exercises->count();

                    // Get unique exercises that have been completed (based on exercise_id)
                    $completedExercises = $programSessions
                        ->where('status', 'completed')
                        ->pluck('exercise_id')
                        ->unique()
                        ->count();

                    // Calculate progress based on unique exercises completed vs total exercises
                    $progress = $totalExercisesInProgram > 0
                        ? round(($completedExercises / $totalExercisesInProgram) * 100)
                        : 0;

                    // Calculate total sessions (could be multiple sessions per exercise)
                    $totalProgramSessions = $programSessions->count();
                    $completedProgramSessions = $programSessions->where('status', 'completed')->count();

                    // Calculate average pain for this program
                    $avgPain = $totalProgramSessions > 0
                        ? round($programSessions->avg('pain_level'), 1)
                        : 0;

                    // Get all unique exercise names for this program
                    $exercises = $assignment->program->exercises->pluck('name')->values();

                    // Get completed exercise names
                    $completedExerciseIds = $programSessions
                        ->where('status', 'completed')
                        ->pluck('exercise_id')
                        ->unique();

                    $completedExerciseNames = $assignment->program->exercises
                        ->whereIn('id', $completedExerciseIds)
                        ->pluck('name')
                        ->values();

                    // Debug log
                    \Log::info('Program Progress Calculation: ' . $assignment->program->name, [
                        'total_exercises_in_program' => $totalExercisesInProgram,
                        'completed_unique_exercises' => $completedExercises,
                        'progress' => $progress . '%',
                        'total_sessions' => $totalProgramSessions,
                        'completed_sessions' => $completedProgramSessions,
                        'completed_exercise_ids' => $completedExerciseIds,
                        'completed_exercise_names' => $completedExerciseNames
                    ]);

                    $programs[] = [
                        'id' => $assignment->program->id,
                        'name' => $assignment->program->name,
                        'description' => $assignment->program->description,
                        'difficulty' => $assignment->program->difficulty,
                        'duration' => $assignment->program->duration,
                        'image_url' => $assignment->program->image_url,
                        'image_alt' => $assignment->program->image_alt,
                        'started_at' => $assignment->started_at,
                        'status' => $assignment->status,
                        'progress' => $progress, // Now based on unique exercises
                        'total_exercises' => $totalExercisesInProgram,
                        'completed_exercises' => $completedExercises,
                        'total_sessions' => $totalProgramSessions,
                        'completed_sessions' => $completedProgramSessions,
                        'average_pain' => $avgPain,
                        'exercises' => $exercises,
                        'completed_exercise_names' => $completedExerciseNames,
                    ];
                }
            }

            // Build patient array
            $patientArray = [
                'id' => $patient->id,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'full_name' => $patient->first_name . ' ' . $patient->last_name,
                'email' => $patient->email,
                'phone' => $patient->phone,
                'date_of_birth' => $patient->date_of_birth,
                'age' => $patient->date_of_birth ? Carbon::parse($patient->date_of_birth)->age : null,
                'address' => $patient->address,
                'city' => $patient->city,
                'postal_code' => $patient->postal_code,
                'country' => $patient->country,
                'avatar_url' => $patient->avatar_url ?: "https://ui-avatars.com/api/?name=" . substr($patient->first_name, 0, 1) . substr($patient->last_name, 0, 1) . "&background=random&color=fff&size=128",
                'is_active' => (bool) $patient->is_active,
                'status' => $patient->is_active ? 'active' : 'inactive',
                'created_at' => $patient->created_at,
                'updated_at' => $patient->updated_at,

                // Profile data
                'profile' => $patient->patientProfile ? [
                    'gender' => $patient->patientProfile->gender,
                    'height_cm' => (int) $patient->patientProfile->height_cm,
                    'weight_kg' => (float) $patient->patientProfile->weight_kg,
                    'medical_notes' => $patient->patientProfile->medical_notes,
                    'emergency_contact_name' => $patient->patientProfile->emergency_contact_name,
                    'emergency_contact_phone' => $patient->patientProfile->emergency_contact_phone,
                    'preferred_contact_method' => $patient->patientProfile->preferred_contact_method,
                    'preferred_language' => $patient->patientProfile->preferred_language,
                ] : null,

                // Loyalty points
                'loyalty_points' => $patient->loyaltyPoints ? [
                    'total_points' => (int) $patient->loyaltyPoints->total_points,
                    'available_points' => (int) $patient->loyaltyPoints->available_points,
                    'level' => (int) $patient->loyaltyPoints->level,
                ] : null,

                // Programs array
                'programs' => $programs,

                // Custom fields
                'adherence_rate' => $adherenceRate,
                'total_sessions' => $totalSessions,
                'completed_sessions' => $completedSessions,
                'current_program' => $currentProgram,
                'total_programs' => $patient->total_programs ?? 0,
                'active_programs' => $patient->active_programs ?? 0,
            ];

            $activePatientsWithPrograms[] = $patientArray;
        }

        return response()->json([
            'success' => true,
            'data' => $activePatientsWithPrograms,
            'meta' => [
                'total' => count($activePatientsWithPrograms),
            ],
        ]);
    }

    /**
     * Get patient statistics for dashboard.
     */
    public function statistics()
    {
        $user = auth()->user();
        $totalPatients = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->count();

        $activePatients = User::where('role', 'patient')
            ->where('is_active', true)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->count();

        $genderStats = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->whereHas('patientProfile')
            ->with('patientProfile')
            ->get()
            ->groupBy(function ($patient) {
                return $patient->patientProfile->gender ?? 'unknown';
            })
            ->map(function ($group) {
                return $group->count();
            });

        $newPatientsThisMonth = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $patientsWithExercises = User::where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->with(['exerciseSessions'])
            ->get();

        $averageAdherence = 0;
        if ($patientsWithExercises->count() > 0) {
            $totalAdherence = 0;
            $patientsWithData = 0;

            foreach ($patientsWithExercises as $patient) {
                $totalSessions = $patient->exerciseSessions->count();
                if ($totalSessions > 0) {
                    $completedSessions = $patient->exerciseSessions->where('status', 'completed')->count();
                    $adherence = ($completedSessions / $totalSessions) * 100;
                    $totalAdherence += $adherence;
                    $patientsWithData++;
                }
            }

            if ($patientsWithData > 0) {
                $averageAdherence = round($totalAdherence / $patientsWithData, 1);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_patients' => $totalPatients,
                'active_patients' => $activePatients,
                'new_patients_this_month' => $newPatientsThisMonth,
                'average_adherence' => $averageAdherence,
                'gender_distribution' => [
                    'male' => $genderStats['male'] ?? 0,
                    'female' => $genderStats['female'] ?? 0,
                    'other' => $genderStats['other'] ?? 0,
                    'unknown' => $genderStats['unknown'] ?? 0,
                ],
            ]
        ]);
    }

    /**
     * Update patient pathologies.
     */
    public function updatePathologies(Request $request, $id)
    {
        $user = auth()->user();

        $patient = User::where('role', 'patient')
            ->where('id', $id)
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        $request->validate([
            'pathologies' => 'array',
            'pathologies.*.id' => 'required|exists:pathologies,id',
            'pathologies.*.diagnosed_date' => 'nullable|date',
            'pathologies.*.notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $syncData = [];
            foreach ($request->pathologies as $pathology) {
                $syncData[$pathology['id']] = [
                    'diagnosed_date' => $pathology['diagnosed_date'] ?? null,
                    'notes' => $pathology['notes'] ?? null,
                    'is_active' => true,
                ];
            }

            $patient->patientProfile->pathologies()->sync($syncData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pathologies mises à jour avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des pathologies',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

     public function getPatientDetail($patientId)
    {
        $user = auth()->user();

        // Verify the patient belongs to this kine
        $patient = User::where('id', $patientId)
            ->where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        // Get patient statistics
        $totalSessions = $patient->exerciseSessions()->count();
        $lastSession = $patient->exerciseSessions()
            ->latest('session_date')
            ->first();
        $data = [
            'id' => $patient->id,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'email' => $patient->email,
            'adherence_rate' => $patient->adherence_rate,
            'total_sessions' => $totalSessions,
            'last_session_date' => $lastSession?->session_date,
            'average_pain' => $patient->exerciseSessions()->avg('pain_level') ?? 0,
            'created_at' => $patient->created_at,
        ];

        return response()->json([
            'data' => $data,
        ]);
    }

    public function getPatientPrograms($patientId)
    {
        $user = auth()->user();

        // Verify the patient belongs to this kine
        $patient = User::where('id', $patientId)
            ->where('role', 'patient')
            ->whereHas('assignedKine', function ($query) use ($user) {
                $query->where('kine_id', $user->id);
            })
            ->firstOrFail();

        // Get patient's active programs
        $programs = $patient->programAssignments()
            ->with(['program'])
            ->where('status', 'active')
            ->get()
            ->map(function ($assignment) {
                $program = $assignment->program;
                $completedSessions = $assignment->sessions()
                    ->where('status', 'completed')
                    ->count();
                $totalExercises = $program->exercises()->count();

                return [
                    'id' => $program->id,
                    'name' => $program->name,
                    'description' => $program->description,
                    'progress' => $totalExercises > 0
                        ? round(($completedSessions / $totalExercises) * 100)
                        : 0,
                    'started_at' => $assignment->started_at->format('Y-m-d'),
                    'status' => $assignment->status,
                    'total_sessions' => $assignment->sessions()->count(),
                    'completed_sessions' => $completedSessions,
                ];
            });

        return response()->json([
            'data' => $programs,
        ]);
    }
}
