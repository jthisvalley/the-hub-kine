<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\KineProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Get kine profile
     */
    public function show()
    {
        $user = Auth::user();

        $user->load('kineProfile');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'role' => $user->role,
                'kine_profile' => $user->kineProfile ? [
                    'id' => $user->kineProfile->id,
                    'specialty' => $user->kineProfile->specialty,
                    'bio' => $user->kineProfile->bio,
                    'address' => $user->kineProfile->address,
                    'city' => $user->kineProfile->city,
                    'postal_code' => $user->kineProfile->postal_code,
                    'siret' => $user->kineProfile->siret,
                    'adeli_number' => $user->kineProfile->adeli_number,
                    'specialties' => $user->kineProfile->specialties ?? [],
                    'years_of_experience' => $user->kineProfile->years_of_experience,
                    'emergency_phone' => $user->kineProfile->emergency_phone,
                    'is_emergency_contact_available' => $user->kineProfile->is_emergency_contact_available,
                    'approved' => $user->kineProfile->approved,
                ] : null,
            ],
        ]);
    }

    /**
     * Update basic profile
     */
    public function updateBasic(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'language' => 'sometimes|string|in:fr,en,es',
            'timezone' => 'sometimes|string|timezone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->update($request->only(['first_name', 'last_name', 'phone']));

        // Update or create kine profile
        $kineProfile = $user->kineProfile ?? new KineProfile(['user_id' => $user->id]);

        // Store preferences in kine profile
        $preferences = $kineProfile->notification_preferences ?? [];
        if ($request->has('language')) {
            $preferences['language'] = $request->language;
        }
        if ($request->has('timezone')) {
            $preferences['timezone'] = $request->timezone;
        }

        if (!empty($preferences)) {
            $kineProfile->notification_preferences = $preferences;
            $kineProfile->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'data' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'language' => $preferences['language'] ?? 'fr',
                'timezone' => $preferences['timezone'] ?? 'Europe/Paris',
            ],
        ]);
    }

    /**
     * Update professional information
     */
    public function updateProfessional(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'specialty' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'siret' => 'nullable|string|max:20',
            'adeli_number' => 'nullable|string|max:20',
            'specialties' => 'nullable|array',
            'specialties.*' => 'string',
            'years_of_experience' => 'nullable|integer|min:0|max:100',
            'emergency_phone' => 'nullable|string|max:20',
            'is_emergency_contact_available' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $kineProfile = $user->kineProfile ?? new KineProfile(['user_id' => $user->id]);

        $kineProfile->fill($request->only([
            'specialty',
            'bio',
            'address',
            'city',
            'postal_code',
            'siret',
            'adeli_number',
            'specialties',
            'years_of_experience',
            'emergency_phone',
            'is_emergency_contact_available',
        ]));

        $kineProfile->save();

        return response()->json([
            'success' => true,
            'message' => 'Informations professionnelles mises à jour',
            'data' => $kineProfile,
        ]);
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_url) {
                $oldPath = str_replace('/storage/', '', parse_url($user->avatar_url, PHP_URL_PATH));
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_url = Storage::url($path);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Photo de profil mise à jour',
                'data' => [
                    'avatar_url' => $user->avatar_url,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Aucun fichier reçu',
        ], 400);
    }
}
