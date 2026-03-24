<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active_programs' => $this['active_programs'],
            'average_adherence' => $this['average_adherence'],
            'average_pain' => $this['average_pain'],
            'weekly_sessions' => $this['weekly_sessions'],
            'active_patients' => $this['active_patients'],
            'completion_rate' => $this['completion_rate'],
            'total_points' => $this['total_points'],
            'level' => $this['level'],
            'streak' => $this['streak'],
            'completed_exercises' => $this['completed_exercises'],
        ];
    }
}
