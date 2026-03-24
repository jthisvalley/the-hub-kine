<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send reminders for upcoming appointments';

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    public function handle()
    {
        $this->info('Starting to send appointment reminders...');

        // Get appointments in the next 24 hours that are scheduled
        $appointments = Appointment::where('status', 'scheduled')
            ->whereHas('slot', function ($query) {
                $query->whereBetween('start_time', [
                    Carbon::now(),
                    Carbon::now()->addHours(24)
                ]);
            })
            ->with(['patient', 'slot.kine', 'reminders'])
            ->get();

        $this->info("Found {$appointments->count()} appointments in the next 24 hours");

        $reminderTypes = [
            24 => '24h',
            2 => '2h',
            1 => '1h',
        ];

        foreach ($appointments as $appointment) {
            $startTime = Carbon::parse($appointment->slot->start_time);
            $hoursUntil = Carbon::now()->diffInHours($startTime, false);

            // Determine which reminder to send
            foreach ($reminderTypes as $hours => $type) {
                if ($hoursUntil <= $hours && $hoursUntil > $hours - 0.5) {
                    $this->sendReminder($appointment, $type, $hours);
                }
            }

            // Check for user-selected reminders
            $this->sendUserSelectedReminders($appointment);
        }

        $this->info('Finished sending reminders');
    }

    /**
     * Send a specific reminder
     */
    protected function sendReminder(Appointment $appointment, string $type, int $hours)
    {
        // Check if this reminder was already sent
        $existingReminder = AppointmentReminder::where('appointment_id', $appointment->id)
            ->where('reminder_type', $type)
            ->where('reminder_hours_before', $hours)
            ->whereNotNull('sent_at')
            ->first();

        if ($existingReminder) {
            $this->info("Reminder {$type} already sent for appointment {$appointment->id}");
            return;
        }

        $this->info("Sending {$type} reminder for appointment {$appointment->id}");

        // Send the reminder
        $result = $this->notificationService->sendAppointmentReminder(
            $appointment,
            $type,
            $hours
        );

        // Record that the reminder was sent
        AppointmentReminder::updateOrCreate(
            [
                'appointment_id' => $appointment->id,
                'reminder_type' => $type,
                'reminder_hours_before' => $hours,
            ],
            [
                'sent_at' => Carbon::now(),
                'status' => 'sent',
            ]
        );

        if ($result) {
            $this->info("Reminder {$type} sent successfully");
        } else {
            $this->error("Failed to send reminder {$type}");
        }
    }

    /**
     * Send user-selected reminders (from appointment creation)
     */
    protected function sendUserSelectedReminders(Appointment $appointment)
    {
        $userReminders = $appointment->reminders()
            ->whereNull('sent_at')
            ->where('status', 'scheduled')
            ->get();

        foreach ($userReminders as $reminder) {
            $hoursBefore = $reminder->reminder_hours_before;
            $startTime = Carbon::parse($appointment->slot->start_time);
            $timeUntil = Carbon::now()->diffInMinutes($startTime, false);

            // Check if it's time to send this reminder (within 5 minutes of the target time)
            $targetMinutes = $hoursBefore * 60;
            $minutesDiff = abs($targetMinutes - $timeUntil);

            if ($minutesDiff <= 5) {
                $this->info("Sending user-selected reminder ({$hoursBefore}h) for appointment {$appointment->id}");

                $type = $hoursBefore == 24 ? '24h' : ($hoursBefore == 2 ? '2h' : 'custom');

                $result = $this->notificationService->sendAppointmentReminder(
                    $appointment,
                    $type,
                    $hoursBefore
                );

                if ($result) {
                    $reminder->update([
                        'sent_at' => Carbon::now(),
                        'status' => 'sent',
                    ]);
                    $this->info("User reminder sent successfully");
                }
            }
        }
    }
}
