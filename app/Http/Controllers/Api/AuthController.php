<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\EmailVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailVerificationMail;
use App\Models\SecurityEvent;
use App\Services\SecurityEventService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class AuthController extends Controller
{

    protected $securityEventService;

    public function __construct(SecurityEventService $securityEventService)
    {
        $this->securityEventService = $securityEventService;
    }

    /**
     * Handle user login with device binding
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email ou mot de passe incorrect'
                ], 401);
            }

            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Votre compte a été désactivé. Veuillez contacter le support.'
                ], 403);
            }

            $deviceFingerprint = UserDevice::generateFingerprint($request);

            if (!$user->email_verified_at) {
                return $this->handleUnverifiedEmail($user, $request->email);
            }

            $device = UserDevice::where('user_id', $user->id)
                ->where('device_fingerprint', $deviceFingerprint)
                ->first();

            if ($device) {
                $device->update([
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                    'last_used_at' => now(),
                    'is_active' => true,
                ]);

                $device->updateLocation()->save();

                Log::info('Existing device updated', [
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                    'fingerprint' => substr($deviceFingerprint, 0, 20) . '...',
                ]);
            } else {
                $device = new UserDevice([
                    'user_id' => $user->id,
                    'device_fingerprint' => $deviceFingerprint,
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                    'last_used_at' => now(),
                    'first_used_at' => now(),
                    'is_active' => true,
                    'is_trusted' => false,
                ]);

                $device->parseUserAgent($request->userAgent())
                    ->updateLocation()
                    ->save();

                Log::info('New device registered', [
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'os' => $device->os,
                    'browser' => $device->browser,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            $tokenModel = $user->tokens()->latest()->first();
            if ($tokenModel) {
                $tokenModel->device_id = $device->id;
                $tokenModel->save();
            }

            $this->securityEventService->log(
                $user,
                SecurityEvent::TYPE_LOGIN_SUCCESS,
                'Connexion réussie',
                $request,
                $device,
                ['method' => 'password'],
                'success'
            );

            $profile = $this->loadUserProfile($user);
            $userData = $this->prepareUserData($user);

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'user' => $userData,
                'profile' => $profile,
                'token' => $token,
                'device_info' => [
                    'fingerprint' => $deviceFingerprint,
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'is_new_device' => !$device->wasRecentlyCreated,
                ],
                'token_type' => 'Bearer',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'email' => $request->email ?? 'unknown',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la connexion. Veuillez réessayer.'
            ], 500);
        }
    }

    /**
     * Handle user registration with device binding and email verification
     */
    public function register(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:users',
                'password' => 'required|min:8|confirmed',
                'role' => 'required|in:kine,patient',
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'phone' => 'nullable|string|max:20',
                'device_fingerprint' => 'required|string',
                'practice_name' => 'required_if:role,kine|string|max:255',
                'siret_number' => 'required_if:role,kine|string|max:50',
                'age' => 'required_if:role,patient|integer|min:1|max:120',
                'pathology' => 'required_if:role,patient|string|max:255',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'phone' => $request->phone,
                'is_active' => true,
                'email_verified_at' => null,
            ]);

            if ($request->role === 'kine') {
                $user->kineProfile()->create([
                    'practice_name' => $request->practice_name,
                    'siret_number' => $request->siret_number,
                    'specialties' => !empty($request->specialties) ? json_encode($request->specialties) : null,
                    'experience' => $request->experience,
                ]);
            } else {
                $user->patientProfile()->create([
                    'age' => $request->age,
                    'pathology' => $request->pathology,
                    'prescribing_physician' => $request->prescribing_physician,
                    'emergency_contact' => $request->emergency_contact,
                ]);
            }

            $deviceFingerprint = $request->device_fingerprint;

            if (!$deviceFingerprint) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Identification de l\'appareil requise'
                ], 400);
            }

            $device = UserDevice::create([
                'user_id' => $user->id,
                'device_fingerprint' => $deviceFingerprint,
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip(),
                'last_used_at' => now(),
                'is_active' => true,
            ]);

            $otp = rand(100000, 999999);
            $expiresAt = Carbon::now()->addMinutes(15);

            EmailVerification::where('email', $request->email)->delete();

            EmailVerification::create([
                'email' => $request->email,
                'otp' => $otp,
                'expires_at' => $expiresAt,
                'user_id' => $user->id,
            ]);

            try {
                Mail::to($request->email)->send(new EmailVerificationMail($otp));
                $emailSent = true;
            } catch (\Exception $e) {
                Log::error('Email sending failed: ' . $e->getMessage());
                $emailSent = false;
            }

            $tempToken = $user->createToken('temp_verification_token')->plainTextToken;

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie ! Un code de vérification a été envoyé à votre adresse email.',
                'requires_verification' => true,
                'email' => $user->email,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->first_name . ' ' . $user->last_name,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'email_verified_at' => null,
                ],
                'temp_token' => $tempToken,
                'device_id' => $device->device_fingerprint,
                'email_sent' => $emailSent,
            ], 201);

        } catch (QueryException $e) {
            DB::rollBack();

            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'user_devices_device_fingerprint_unique')) {
                Log::error('Duplicate device fingerprint during registration: ' . $e->getMessage());

                try {
                    DB::beginTransaction();

                    $user = User::where('email', $request->email)->first();
                    if ($user) {
                        $user->delete();
                    }

                    DB::commit();

                    return response()->json([
                        'success' => false,
                        'message' => 'Une erreur est survenue avec l\'identification de l\'appareil. Veuillez réessayer.'
                    ], 409);
                } catch (\Exception $retryError) {
                    DB::rollBack();
                    Log::error('Retry error: ' . $retryError->getMessage());

                    return response()->json([
                        'success' => false,
                        'message' => 'Une erreur est survenue lors de l\'inscription'
                    ], 500);
                }
            }

            Log::error('Registration query error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'inscription'
            ], 500);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Registration error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'inscription'
            ], 500);
        }
    }

    /**
     * Verify email with OTP
     */
    public function verifyEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'otp' => 'required|string|size:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $emailVerification = EmailVerification::where('email', $request->email)
                ->where('otp', $request->otp)
                ->first();

            if (!$emailVerification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code OTP incorrect'
                ], 400);
            }

            if (Carbon::now()->greaterThan($emailVerification->expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le code OTP a expiré. Veuillez en demander un nouveau.'
                ], 400);
            }

            $user = User::where('email', $request->email)->first();
            $user->email_verified_at = now();
            $user->save();

            $emailVerification->delete();

            $deviceFingerprint = $request->header('X-Device-Fingerprint');

            $token = $user->createToken('auth_token')->plainTextToken;

            if ($deviceFingerprint) {
                $device = UserDevice::where('user_id', $user->id)
                    ->where('device_fingerprint', $deviceFingerprint)
                    ->first();

                if (!$device) {
                    $device = UserDevice::where('user_id', $user->id)
                        ->where('device_fingerprint', 'like', substr($deviceFingerprint, 0, 20) . '%')
                        ->first();
                }

                if ($device) {
                    $personalAccessToken = $user->tokens()->where('name', 'auth_token')->latest()->first();
                    $personalAccessToken->device_id = $device->id;
                    $personalAccessToken->save();
                }
            }

            $tempToken = $request->bearerToken();
            if ($tempToken) {
                $tempTokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($tempToken);
                if ($tempTokenModel && $tempTokenModel->name === 'temp_verification_token') {
                    $tempTokenModel->delete();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Email vérifié avec succès ! Votre compte est maintenant actif.',
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->first_name . ' ' . $user->last_name,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'email_verified_at' => $user->email_verified_at,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (\Exception $e) {
            Log::error('Email verification error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la vérification de l\'email'
            ], 500);
        }
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email invalide'
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

            if ($user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet email est déjà vérifié'
                ], 400);
            }

            $lastOtp = EmailVerification::where('email', $request->email)
                ->where('created_at', '>', Carbon::now()->subMinute())
                ->first();

            if ($lastOtp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Veuillez attendre 60 secondes avant de demander un nouveau code'
                ], 429);
            }

            $otp = rand(100000, 999999);
            $expiresAt = Carbon::now()->addMinutes(15);

            EmailVerification::where('email', $request->email)->delete();

            EmailVerification::create([
                'email' => $request->email,
                'otp' => $otp,
                'expires_at' => $expiresAt,
                'user_id' => $user->id,
            ]);

            try {
                Mail::to($request->email)->send(new EmailVerificationMail($otp));
                $emailSent = true;
            } catch (\Exception $e) {
                Log::error('Resend OTP email failed: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Échec de l\'envoi de l\'email. Veuillez réessayer.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Un nouveau code de vérification a été envoyé à votre adresse email.',
                'email_sent' => $emailSent,
            ]);

        } catch (\Exception $e) {
            Log::error('Resend OTP error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du code'
            ], 500);
        }
    }

    /**
     * Check OTP status
     */
    public function checkOtpStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email invalide'
                ], 422);
            }

            $user = User::where('email', $request->email)->first();
            $otpRecord = EmailVerification::where('email', $request->email)->first();

            return response()->json([
                'success' => true,
                'email_verified' => !is_null($user->email_verified_at),
                'has_pending_otp' => !is_null($otpRecord),
                'otp_expires_at' => $otpRecord ? $otpRecord->expires_at : null,
                'can_resend' => is_null($otpRecord) || $otpRecord->created_at->lt(Carbon::now()->subMinute()),
            ]);

        } catch (\Exception $e) {
            Log::error('Check OTP status error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Get authenticated user with device verification
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            // RÉCUPÉRATION ET VALIDATION DU DEVICE FINGERPRINT
            $deviceFingerprint = $this->getDeviceFingerprintFromRequest($request, $user);

            if (!$deviceFingerprint) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identification de l\'appareil requise. Veuillez vous reconnecter.',
                    'requires_relogin' => true,
                ], 400);
            }

            // RECHERCHE DE L'APPAREIL AVEC MULTIPLES STRATÉGIES
            $device = $this->findUserDevice($user, $deviceFingerprint);

            if (!$device) {
                // Tentative de récupération via le token
                $device = $this->findDeviceViaToken($request, $user);

                if (!$device) {
                    // Dernière tentative : chercher le dernier appareil utilisé
                    $device = $this->findLastUsedDevice($user);

                    if (!$device) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Appareil non autorisé ou non reconnu. Veuillez vous reconnecter.',
                            'requires_relogin' => true,
                            'debug_info' => [
                                'provided_fingerprint' => substr($deviceFingerprint, 0, 20) . '...',
                                'user_id' => $user->id,
                            ]
                        ], 403);
                    }

                    // Mettre à jour le fingerprint avec celui trouvé
                    $deviceFingerprint = $device->device_fingerprint;
                }
            }

            // Vérifier si l'appareil est actif
            if (!$device->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet appareil a été bloqué. Veuillez contacter le support.'
                ], 403);
            }

            // Mettre à jour la dernière utilisation
            $device->update(['last_used_at' => now()]);

            // Synchroniser le token avec l'appareil
            $this->syncTokenWithDevice($request, $user, $device);

            // Charger le profil
            $this->loadUserProfile($user);

            // Préparer les données de réponse
            $response = $this->prepareMeResponse($user, $device, $deviceFingerprint);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Get me error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id ?? 'unknown',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des données utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Logout user and revoke device token
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            $deviceFingerprint = $request->header('X-Device-Fingerprint');

            if ($deviceFingerprint) {
                $device = UserDevice::where('user_id', $user->id)
                    ->where('device_fingerprint', $deviceFingerprint)
                    ->first();

                if ($device) {
                    $device->tokens()->delete();
                }
            } else {
                $user->tokens()->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie'
            ]);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la déconnexion'
            ], 500);
        }
    }

    /**
     * Check if device is authorized
     */
    public function checkDevice(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié'
                ], 401);
            }

            $deviceFingerprint = $request->header('X-Device-Fingerprint');

            if (!$deviceFingerprint) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identification de l\'appareil requise'
                ], 400);
            }

            $device = UserDevice::where('user_id', $user->id)
                ->where('device_fingerprint', $deviceFingerprint)
                ->where('is_active', true)
                ->first();

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appareil non autorisé'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Appareil autorisé',
                'device' => [
                    'id' => $device->id,
                    'device_fingerprint' => $device->device_fingerprint,
                    'last_used_at' => $device->last_used_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Device check error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

        /**
     * Méthodes helper pour la gestion des appareils
     */

    private function generateOrRetrieveDeviceFingerprint(Request $request, User $user): string
    {
        // 1. Priorité : fingerprint fourni dans le header
        $headerFingerprint = $request->header('X-Device-Fingerprint');
        if ($headerFingerprint && strlen($headerFingerprint) >= 32) {
            return $headerFingerprint;
        }

        // 2. Priorité : fingerprint fourni dans le body
        $bodyFingerprint = $request->input('device_fingerprint');
        if ($bodyFingerprint && strlen($bodyFingerprint) >= 32) {
            return $bodyFingerprint;
        }

        // 3. Générer un fingerprint basé sur des informations stables
        return $this->generateStableFingerprint($request, $user);
    }

    private function generateStableFingerprint(Request $request, User $user): string
    {
        $components = [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent', 'unknown'),
            'accept_language' => $request->header('Accept-Language', 'unknown'),
            'platform' => $this->extractPlatformFromUserAgent($request->header('User-Agent')),
        ];

        // Ajouter un salt pour éviter les collisions
        $salt = config('app.device_fingerprint_salt', 'default_salt_change_in_production');

        $fingerprintString = json_encode($components) . $salt;

        return hash('sha256', $fingerprintString);
    }

    private function extractPlatformFromUserAgent(?string $userAgent): string
    {
        if (!$userAgent) return 'unknown';

        $userAgent = strtolower($userAgent);

        if (strpos($userAgent, 'mobile') !== false) return 'mobile';
        if (strpos($userAgent, 'android') !== false) return 'android';
        if (strpos($userAgent, 'iphone') !== false) return 'ios';
        if (strpos($userAgent, 'ipad') !== false) return 'ios';
        if (strpos($userAgent, 'windows') !== false) return 'windows';
        if (strpos($userAgent, 'mac') !== false) return 'mac';
        if (strpos($userAgent, 'linux') !== false) return 'linux';

        return 'desktop';
    }

    private function handleDeviceRegistration(User $user, string $deviceFingerprint, Request $request): UserDevice
    {
        // Chercher d'abord un appareil existant pour cet utilisateur avec ce fingerprint
        $device = UserDevice::where('user_id', $user->id)
            ->where('device_fingerprint', $deviceFingerprint)
            ->first();

        if ($device) {
            // Mettre à jour les informations existantes
            $device->update([
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip(),
                'last_used_at' => now(),
            ]);

            Log::info('Device updated', [
                'user_id' => $user->id,
                'device_id' => $device->id,
                'fingerprint' => substr($deviceFingerprint, 0, 20) . '...',
            ]);
        } else {
            // Vérifier si ce fingerprint est déjà utilisé par un autre utilisateur
            $existingDevice = UserDevice::where('device_fingerprint', $deviceFingerprint)
                ->where('user_id', '!=', $user->id)
                ->first();

            if ($existingDevice) {
                // Générer un nouveau fingerprint unique pour cet utilisateur
                $deviceFingerprint = hash('sha256', $deviceFingerprint . $user->id . time() . Str::random(10));
            }

            // Créer un nouvel appareil
            $device = UserDevice::create([
                'user_id' => $user->id,
                'device_fingerprint' => $deviceFingerprint,
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip(),
                'last_used_at' => now(),
                'is_active' => true,
                'first_used_at' => now(),
            ]);

            Log::info('New device registered', [
                'user_id' => $user->id,
                'device_id' => $device->id,
                'fingerprint' => substr($deviceFingerprint, 0, 20) . '...',
                'user_agent' => substr($request->header('User-Agent'), 0, 100),
            ]);
        }

        return $device;
    }

    private function associateTokenWithDevice(User $user, string $token, UserDevice $device): void
    {
        $tokenModel = $user->tokens()
            ->where('name', 'auth_token')
            ->latest()
            ->first();

        if ($tokenModel) {
            $tokenModel->device_id = $device->id;
            $tokenModel->save();

            Log::debug('Token associated with device', [
                'token_id' => $tokenModel->id,
                'device_id' => $device->id,
            ]);
        }
    }

    private function loadUserProfile(User $user)
    {
        if ($user->role === 'kine') {
            $user->load('kineProfile');
            return $user->kineProfile;
        } elseif ($user->role === 'patient') {
            $user->load('patientProfile');
            return $user->patientProfile;
        }

        return null;
    }

    private function prepareUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->first_name . ' ' . $user->last_name,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at,
        ];
    }

    private function handleUnverifiedEmail(User $user, string $email)
    {
        $otp = rand(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(15);

        EmailVerification::where('email', $email)->delete();

        EmailVerification::create([
            'email' => $email,
            'otp' => $otp,
            'expires_at' => $expiresAt,
            'user_id' => $user->id,
        ]);

        try {
            Mail::to($email)->send(new EmailVerificationMail($otp));
            $emailSent = true;
        } catch (\Exception $e) {
            Log::error('Email sending failed: ' . $e->getMessage());
            $emailSent = false;
        }

        $tempToken = $user->createToken('temp_verification_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Veuillez vérifier votre adresse email avant de vous connecter',
            'requires_verification' => true,
            'email' => $user->email,
            'temp_token' => $tempToken,
            'email_sent' => $emailSent,
        ], 200);
    }

    private function getDeviceFingerprintFromRequest(Request $request, User $user): ?string
    {
        // 1. Chercher dans les headers
        $fingerprint = $request->header('X-Device-Fingerprint');

        if ($fingerprint && strlen($fingerprint) >= 32) {
            return $fingerprint;
        }

        // 2. Chercher dans les paramètres de requête (pour le débogage)
        $fingerprint = $request->query('device_fingerprint');
        if ($fingerprint && strlen($fingerprint) >= 32) {
            return $fingerprint;
        }

        // 3. Générer un fingerprint basé sur la requête actuelle
        // (utile si le client a perdu le fingerprint)
        return $this->generateStableFingerprint($request, $user);
    }

    private function findUserDevice(User $user, string $deviceFingerprint): ?UserDevice
    {
        // 1. Recherche exacte
        $device = UserDevice::where('user_id', $user->id)
            ->where('device_fingerprint', $deviceFingerprint)
            ->first();

        if ($device) {
            return $device;
        }

        // 2. Recherche partielle (au cas où le fingerprint aurait été tronqué)
        if (strlen($deviceFingerprint) > 32) {
            $partialFingerprint = substr($deviceFingerprint, 0, 32);
            $device = UserDevice::where('user_id', $user->id)
                ->where('device_fingerprint', 'like', $partialFingerprint . '%')
                ->first();

            if ($device) {
                Log::warning('Device found with partial fingerprint match', [
                    'user_id' => $user->id,
                    'provided' => $deviceFingerprint,
                    'found' => $device->device_fingerprint,
                ]);
                return $device;
            }
        }

        return null;
    }

    private function findDeviceViaToken(Request $request, User $user): ?UserDevice
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

        if ($tokenModel && $tokenModel->device_id) {
            $device = UserDevice::where('id', $tokenModel->device_id)
                ->where('user_id', $user->id)
                ->first();

            if ($device) {
                Log::info('Device found via token association', [
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                    'token_id' => $tokenModel->id,
                ]);
                return $device;
            }
        }

        return null;
    }

    private function findLastUsedDevice(User $user): ?UserDevice
    {
        return UserDevice::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('last_used_at', 'desc')
            ->first();
    }

    private function syncTokenWithDevice(Request $request, User $user, UserDevice $device): void
    {
        $token = $request->bearerToken();

        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

            if ($tokenModel && $tokenModel->device_id !== $device->id) {
                $tokenModel->device_id = $device->id;
                $tokenModel->save();

                Log::info('Token synced with device', [
                    'token_id' => $tokenModel->id,
                    'old_device_id' => $tokenModel->getOriginal('device_id'),
                    'new_device_id' => $device->id,
                ]);
            }
        }
    }

    private function prepareMeResponse(User $user, UserDevice $device, string $deviceFingerprint): array
    {
        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => $user->first_name . ' ' . $user->last_name,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at,
            ],
            'device' => [
                'id' => $device->id,
                'fingerprint' => $device->device_fingerprint,
                'last_used_at' => $device->last_used_at->format('Y-m-d H:i:s'),
                'is_active' => $device->is_active,
            ],
            'fingerprint_match' => $device->device_fingerprint === $deviceFingerprint,
            'instructions' => $device->device_fingerprint !== $deviceFingerprint
                ? 'Utilisez ce fingerprint pour les prochains appels: ' . $device->device_fingerprint
                : 'Fingerprint correctement synchronisé',
        ];
    }

}
