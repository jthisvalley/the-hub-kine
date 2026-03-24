<?php

namespace Database\Seeders;

use App\Models\AppointmentSlot;
use App\Models\User;
use Illuminate\Database\Seeder;

class AppointmentSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kines = User::where('role', 'kine')->where('is_active', true)->get();

        foreach ($kines as $kine) {
            for ($day = 1; $day <= 14; $day++) {
                $date = now()->addDays($day);

                if ($date->isWeekend()) {
                    continue;
                }

                $numSlots = rand(4, 6);

                for ($slot = 0; $slot < $numSlots; $slot++) {
                    $startTime = $date->copy()
                        ->setHour(8 + $slot * 2)
                        ->setMinute(0)
                        ->setSecond(0);

                    $endTime = $startTime->copy()->addHours(1);

                    $isAvailable = rand(0, 3) !== 0;

                    AppointmentSlot::create([
                        'kine_id' => $kine->id,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'is_available' => $isAvailable,
                    ]);
                }
            }
        }

        $this->command->info('✅ Appointment slots seeded successfully!');
    }
}
