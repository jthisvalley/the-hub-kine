<?php

namespace App\Http\Controllers\Api\Kine;

use App\Http\Controllers\Controller;
use App\Services\RewardSystemService;
use App\Models\ExerciseSession;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Http\Request;

class RewardsController extends Controller
{
    protected $rewardService;

    public function __construct(RewardSystemService $rewardService)
    {
        $this->rewardService = $rewardService;
    }

    public function recordExerciseCompletion($sessionId)
    {
        $session = ExerciseSession::findOrFail($sessionId);

        // Verify the session is completed
        if ($session->status !== 'completed') {
            return response()->json([
                'error' => 'Session is not completed yet'
            ], 400);
        }

        $result = $this->rewardService->recordExerciseCompletion($session);

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    public function getPatientStats($patientId)
    {
        $stats = $this->rewardService->getPatientStats($patientId);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function getAvailableRewards($patientId)
    {
        $patient = User::findOrFail($patientId);
        $points = $patient->points;

        $rewards = Reward::where('available', true)
            ->where('stock', '>', 0)
            ->where('points_cost', '<=', $points)
            ->orderBy('points_cost', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'available_points' => $points,
                'rewards' => $rewards
            ]
        ]);
    }
}
