<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PatientRewardController extends Controller
{
    // Define available rewards
    private $rewards = [
        'free_session' => [
            'id' => 'free_session',
            'title' => 'Séance offerte',
            'description' => 'Une séance de kinésithérapie gratuite',
            'points' => 2000,
            'type' => 'service',
        ],
        'discount_10' => [
            'id' => 'discount_10',
            'title' => 'Réduction 10%',
            'description' => 'Sur votre prochain pack de séances',
            'points' => 500,
            'type' => 'discount',
        ],
        'teleconsultation' => [
            'id' => 'teleconsultation',
            'title' => 'Téléconsultation',
            'description' => 'Consultation vidéo de 30 minutes',
            'points' => 800,
            'type' => 'service',
        ],
        'equipment_discount' => [
            'id' => 'equipment_discount',
            'title' => 'Équipement',
            'description' => 'Réduction sur du matériel d\'exercice',
            'points' => 300,
            'type' => 'discount',
        ],
        'premium_content' => [
            'id' => 'premium_content',
            'title' => 'Contenu Premium',
            'description' => 'Accès à des exercices et conseils exclusifs',
            'points' => 400,
            'type' => 'content',
        ],
    ];

    /**
     * Get available rewards
     */
    public function index(Request $request)
    {
        $patient = Auth::user();

        // In a real application, you would get the patient's total points from database
        $totalPoints = $this->calculatePatientPoints($patient);

        $availableRewards = array_map(function ($reward) use ($totalPoints) {
            return [
                'id' => $reward['id'],
                'title' => $reward['title'],
                'description' => $reward['description'],
                'points' => $reward['points'],
                'type' => $reward['type'],
                'can_claim' => $totalPoints >= $reward['points'],
            ];
        }, $this->rewards);

        // Filter by type if requested
        if ($request->has('type')) {
            $availableRewards = array_filter($availableRewards, function ($reward) use ($request) {
                return $reward['type'] === $request->type;
            });
        }

        return response()->json([
            'success' => true,
            'data' => array_values($availableRewards),
            'patient_points' => $totalPoints,
        ]);
    }

    /**
     * Claim a reward
     */
    public function claim(Request $request, $rewardId)
    {
        $validator = Validator::make(['reward_id' => $rewardId], [
            'reward_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Invalid reward ID'
            ], 422);
        }

        // Check if reward exists
        if (!isset($this->rewards[$rewardId])) {
            return response()->json([
                'success' => false,
                'message' => 'Reward not found'
            ], 404);
        }

        $patient = Auth::user();
        $reward = $this->rewards[$rewardId];

        // Calculate patient's current points
        $totalPoints = $this->calculatePatientPoints($patient);

        // Check if patient has enough points
        if ($totalPoints < $reward['points']) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient points. You need ' . $reward['points'] . ' points but only have ' . $totalPoints,
                'required_points' => $reward['points'],
                'current_points' => $totalPoints,
                'points_needed' => $reward['points'] - $totalPoints,
            ], 400);
        }

        // In a real application, you would:
        // 1. Deduct points from patient's account
        // 2. Create a reward claim record
        // 3. Generate a voucher/coupon if applicable
        // 4. Send notification to patient and admin

        // For now, we'll just return success
        $remainingPoints = $totalPoints - $reward['points'];

        return response()->json([
            'success' => true,
            'message' => 'Reward claimed successfully!',
            'data' => [
                'reward' => [
                    'id' => $reward['id'],
                    'title' => $reward['title'],
                    'description' => $reward['description'],
                    'points_spent' => $reward['points'],
                ],
                'points' => [
                    'previous_total' => $totalPoints,
                    'points_spent' => $reward['points'],
                    'remaining_points' => $remainingPoints,
                ],
                'claim_details' => [
                    'claim_id' => 'CLAIM-' . strtoupper(uniqid()),
                    'claimed_at' => now()->format('Y-m-d H:i:s'),
                    'expires_at' => now()->addMonths(3)->format('Y-m-d'),
                    'instructions' => $this->getRewardInstructions($reward['id']),
                ],
            ],
        ]);
    }

    /**
     * Calculate patient's total points (simplified)
     */
    private function calculatePatientPoints($patient)
    {
        // In a real application, you would calculate based on:
        // 1. Completed exercise sessions
        // 2. Achievements earned
        // 3. Check-ins made
        // 4. Consistency bonuses

        // For simplicity, let's assume 10 points per completed session
        $completedSessions = \App\Models\ExerciseSession::where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->count();

        return $completedSessions * 10;
    }   

    /**
     * Get instructions for claimed reward
     */
    private function getRewardInstructions($rewardId)
    {
        $instructions = [
            'free_session' => 'Présentez ce code lors de votre prochain rendez-vous pour bénéficier d\'une séance gratuite. Valable 3 mois.',
            'discount_10' => 'Utilisez ce code lors du paiement de votre prochain pack de séances pour obtenir 10% de réduction. Valable 3 mois.',
            'teleconsultation' => 'Contactez notre secrétariat pour planifier votre téléconsultation gratuite de 30 minutes. Valable 1 mois.',
            'equipment_discount' => 'Ce code vous donne droit à une réduction sur notre sélection d\'équipement. À utiliser sur notre boutique en ligne.',
            'premium_content' => 'Accédez à votre contenu premium depuis la section "Ressources" de votre espace patient. Accès illimité.',
        ];

        return $instructions[$rewardId] ?? 'Veuillez contacter notre équipe pour utiliser votre récompense.';
    }

    /**
     * Get patient's reward history
     */
    public function history(Request $request)
    {
        $patient = Auth::user();

        // In a real application, you would fetch from a reward_claims table
        // For now, return empty array or mock data

        return response()->json([
            'success' => true,
            'data' => [],
            'message' => 'No reward history found',
        ]);
    }
}
