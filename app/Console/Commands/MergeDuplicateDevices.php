<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeDuplicateDevices extends Command
{
    protected $signature = 'devices:merge-duplicates';
    protected $description = 'Merge duplicate devices based on fingerprint';

    public function handle()
    {
        $this->info('Merging duplicate devices...');

        $users = User::where('role', 'kine')->get();
        $totalMerged = 0;

        foreach ($users as $user) {
            $devices = UserDevice::where('user_id', $user->id)
                ->orderBy('last_used_at', 'desc')
                ->get();

            $uniqueFingerprints = [];

            foreach ($devices as $device) {
                if (!in_array($device->device_fingerprint, $uniqueFingerprints)) {
                    $uniqueFingerprints[] = $device->device_fingerprint;
                } else {
                    $mainDevice = $devices->where('device_fingerprint', $device->device_fingerprint)->first();

                    DB::table('personal_access_tokens')
                        ->where('device_id', $device->id)
                        ->update(['device_id' => $mainDevice->id]);

                    DB::table('security_events')
                        ->where('device_id', $device->id)
                        ->update(['device_id' => $mainDevice->id]);

                    $device->delete();
                    $totalMerged++;
                }
            }
        }

        $this->info("Merged {$totalMerged} duplicate devices.");
    }
}
