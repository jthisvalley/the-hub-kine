<?php

use App\Models\LoyaltyPoints;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::call(function () {
//     LoyaltyPoints::where('exercises_completed_today', '>', 0)
//         ->update(['exercises_completed_today' => 0]);
// })->dailyAt('00:00');
