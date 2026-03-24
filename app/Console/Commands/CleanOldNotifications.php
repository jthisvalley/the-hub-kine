<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanOldNotifications extends Command
{
    protected $signature = 'notifications:clean';
    protected $description = 'Delete old read notifications';

    public function handle()
    {
        $count = Notification::where('is_read', true)
            ->where('created_at', '<', Carbon::now()->subDays(30))
            ->delete();

        $this->info("Deleted {$count} old notifications");
    }
}
