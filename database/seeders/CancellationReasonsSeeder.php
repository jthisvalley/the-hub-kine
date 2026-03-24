<?php

namespace Database\Seeders;

use App\Models\CancellationReason;
use Illuminate\Database\Seeder;

class CancellationReasonsSeeder extends Seeder
{
    public function run()
    {
        $reasons = [
            ['reason' => 'Problème de santé', 'type' => 'health', 'order_index' => 1],
            ['reason' => 'Contraintes personnelles', 'type' => 'personal', 'order_index' => 2],
            ['reason' => 'Problèmes de transport', 'type' => 'transport', 'order_index' => 3],
            ['reason' => 'Motifs financiers', 'type' => 'financial', 'order_index' => 4],
            ['reason' => 'Conflit d\'horaire', 'type' => 'schedule', 'order_index' => 5],
            ['reason' => 'Rendez-vous médical', 'type' => 'health', 'order_index' => 6],
            ['reason' => 'Problème familial', 'type' => 'personal', 'order_index' => 7],
            ['reason' => 'Vacances', 'type' => 'personal', 'order_index' => 8],
            ['reason' => 'Autre raison', 'type' => 'general', 'order_index' => 9],
        ];

        foreach ($reasons as $reason) {
            CancellationReason::firstOrCreate(
                ['reason' => $reason['reason']],
                $reason
            );
        }
    }
}
