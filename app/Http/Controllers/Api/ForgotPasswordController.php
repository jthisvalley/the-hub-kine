<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset link
     */
    public function sendResetLink(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email invalide ou non trouvé',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Votre compte a été désactivé. Veuillez contacter le support.'
                ], 403);
            }

            $token = Str::random(60);

            PasswordResetToken::updateOrInsert(
                ['email' => $request->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => Carbon::now()
                ]
            );

            $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

            try {
                Mail::to($request->email)->send(new PasswordResetMail($resetUrl));

                return response()->json([
                    'success' => true,
                    'message' => 'Un lien de réinitialisation a été envoyé à votre adresse email.',
                    'reset_link' => $resetUrl
                ], 200);
            } catch (\Exception $e) {
                Log::error('Password reset email sending failed: ' . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Échec de l\'envoi de l\'email. Veuillez réessayer.'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Send reset link error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du lien de réinitialisation'
            ], 500);
        }
    }

    /**
     * Verify reset token
     */
    public function verifyResetToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides'
                ], 422);
            }

            $resetData = PasswordResetToken::where('email', $request->email)
                ->first();

            if (!$resetData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de réinitialisation invalide ou expiré'
                ], 400);
            }

            $createdAt = Carbon::parse($resetData->created_at);
            if (Carbon::now()->diffInMinutes($createdAt) > 60) {
                PasswordResetToken::where('email', $request->email)->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Le lien de réinitialisation a expiré. Veuillez en demander un nouveau.'
                ], 400);
            }

            if (!Hash::check($request->token, $resetData->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de réinitialisation invalide'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Token valide',
            ]);

        } catch (\Exception $e) {
            Log::error('Verify reset token error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la vérification du token'
            ], 500);
        }
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'token' => 'required|string',
                'password' => 'required|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $resetData = PasswordResetToken::where('email', $request->email)
                ->first();

            if (!$resetData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de réinitialisation invalide ou expiré'
                ], 400);
            }

            $createdAt = Carbon::parse($resetData->created_at);
            if (Carbon::now()->diffInMinutes($createdAt) > 60) {
                PasswordResetToken::where('email', $request->email)->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Le lien de réinitialisation a expiré. Veuillez en demander un nouveau.'
                ], 400);
            }

            if (!Hash::check($request->token, $resetData->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de réinitialisation invalide'
                ], 400);
            }

            $user = User::where('email', $request->email)->first();
            $user->password = Hash::make($request->password);
            $user->save();

            PasswordResetToken::where('email', $request->email)->delete();

            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe réinitialisé avec succès. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.',
            ]);

        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la réinitialisation du mot de passe'
            ], 500);
        }
    }
}
