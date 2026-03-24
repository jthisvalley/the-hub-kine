<?php

namespace App\Services;

use App\Models\User;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Events\NewNotification;
use App\Events\AppointmentReminder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Resend\Laravel\Facades\Resend;

class NotificationService
{
    protected $resend;
    protected $dailyCountKey = 'notification_daily_count_';

    public function __construct()
    {
        $this->resend = Resend::client("re_J8Zfh2hQ_M2bFqSeCQDUEYkF91pb8ffpN");
    }

    /**
     * Send notification through multiple channels based on preferences
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $actionUrl = null,
        string $priority = 'medium'
    ) {
        $preferences = $this->getPreferences($user);

        // Check daily limit
        if (!$this->checkDailyLimit($user, $preferences)) {
            Log::info("Daily notification limit reached for user {$user->id}");
            return false;
        }

        // Check if we can send based on quiet hours and priority
        if (!$preferences->canSendNotification($type, $priority)) {
            Log::info("Notification blocked by quiet hours or priority for user {$user->id}");
            return false;
        }

        $results = [];

        // Send email
        if ($preferences->email_notifications && $this->shouldSendForType($type, 'email', $preferences)) {
            $results['email'] = $this->sendEmail($user, $title, $message, $data);
        }

        // Send SMS (always for cancellations)
        if ($type === 'appointment.cancelled' || ($preferences->sms_notifications && $this->shouldSendForType($type, 'sms', $preferences))) {
            $results['sms'] = $this->sendSMS($user, $this->formatSMSMessage($message), $data);
        }

        // Send WhatsApp
        if ($preferences->whatsapp_notifications && $this->shouldSendForType($type, 'whatsapp', $preferences)) {
            $results['whatsapp'] = $this->sendWhatsApp($user, $message, $data);
        }

        // Send push notification
        if ($preferences->push_notifications && $this->shouldSendForType($type, 'push', $preferences)) {
            $results['push'] = $this->sendPushNotification($user, $type, $title, $message, $data, $actionUrl, $priority);
        }

        // Increment daily count
        $this->incrementDailyCount($user);

        return $results;
    }

    /**
     * Send appointment reminder
     */
    public function sendAppointmentReminder(Appointment $appointment, string $reminderType, int $hoursBefore)
    {
        $patient = $appointment->patient;
        $kine = $appointment->slot->kine;

        if (!$patient) {
            Log::error("Patient not found for appointment {$appointment->id}");
            return false;
        }

        $startTime = $appointment->slot->start_time;
        $formattedDate = $startTime->format('d/m/Y');
        $formattedTime = $startTime->format('H:i');

        $messageData = $this->getReminderMessage($reminderType, $hoursBefore, $formattedDate, $formattedTime, $appointment, $kine);

        $data = [
            'appointment_id' => $appointment->id,
            'start_time' => $appointment->slot->start_time->toISOString(),
            'type' => $appointment->type,
            'location' => $appointment->location,
            'is_online' => $appointment->is_online,
            'video_link' => $appointment->video_link,
            'reminder_type' => $reminderType,
            'hours_before' => $hoursBefore,
            'kine_name' => $kine?->full_name,
            'kine_phone' => $kine?->phone,
        ];

        $priority = $hoursBefore <= 2 ? 'high' : 'medium';

        // Send to patient
        $this->send(
            $patient,
            'appointment.reminder',
            $messageData['title'],
            $messageData['message'],
            $data,
            "/patient/appointments/{$appointment->id}",
            $priority
        );

        // Send to kine (always push)
        if ($kine) {
            $this->sendPushNotification(
                $kine,
                'appointment.reminder',
                'Rappel de rendez-vous',
                "Rappel: Rendez-vous avec {$patient->full_name} à {$formattedTime}",
                $data,
                "/kine/calendar?appointment={$appointment->id}",
                $priority
            );
        }

        // Broadcast real-time event
        broadcast(new AppointmentReminder($patient, $appointment, $reminderType));

        return true;
    }

    /**
     * Send cancellation notification
     */
    public function sendCancellationNotification(Appointment $appointment, string $cancelledBy, ?string $reason = null)
    {
        $patient = $appointment->patient;
        $kine = $appointment->slot->kine;

        if (!$patient || !$kine) {
            return false;
        }

        $startTime = $appointment->slot->start_time;
        $formattedDate = $startTime->format('d/m/Y H:i');

        $data = [
            'appointment_id' => $appointment->id,
            'cancelled_by' => $cancelledBy,
            'reason' => $reason,
            'kine_name' => $kine->full_name,
            'patient_name' => $patient->full_name,
        ];

        // Notify patient
        if ($cancelledBy === 'kine') {
            $this->send(
                $patient,
                'appointment.cancelled',
                'Rendez-vous annulé',
                "Votre rendez-vous du {$formattedDate} avec {$kine->full_name} a été annulé." . ($reason ? " Raison: {$reason}" : ""),
                $data,
                "/patient/appointments",
                'high'
            );
        }

        // Notify kine
        if ($cancelledBy === 'patient') {
            $this->send(
                $kine,
                'appointment.cancelled',
                'Rendez-vous annulé par le patient',
                "Le rendez-vous du {$formattedDate} avec {$patient->full_name} a été annulé." . ($reason ? " Raison: {$reason}" : ""),
                $data,
                "/kine/calendar",
                'high'
            );
        }

        return true;
    }

    /**
     * Send appointment confirmation
     */
    public function sendConfirmationNotification(Appointment $appointment)
    {
        $patient = $appointment->patient;
        $kine = $appointment->slot->kine;

        if (!$patient) {
            return false;
        }

        $startTime = $appointment->slot->start_time;
        $formattedDate = $startTime->format('d/m/Y');
        $formattedTime = $startTime->format('H:i');

        $location = $appointment->location === 'online' ? 'en ligne' : 'au cabinet';

        $data = [
            'appointment_id' => $appointment->id,
            'start_time' => $appointment->slot->start_time->toISOString(),
            'type' => $appointment->type,
            'location' => $appointment->location,
            'is_online' => $appointment->is_online,
            'kine_name' => $kine?->full_name,
        ];

        return $this->send(
            $patient,
            'appointment.confirmed',
            'Rendez-vous confirmé',
            "Votre rendez-vous du {$formattedDate} à {$formattedTime} {$location} avec {$kine->full_name} a été confirmé.",
            $data,
            "/patient/appointments/{$appointment->id}",
            'medium'
        );
    }

    /**
     * Send email
     */
    protected function sendEmail(User $user, string $title, string $message, array $data = [])
    {
        if (!$user->email) {
            return false;
        }

        try {
            $html = view('emails.notification', [
                'user' => $user,
                'title' => $title,
                'message' => $message,
                'data' => $data
            ])->render();

            $this->resend->emails->send([
                'from' => env('MAIL_FROM_ADDRESS', 'notifications@lehubkine.com'),
                'to' => $user->email,
                'subject' => $title,
                'html' => $html,
            ]);

            Log::info("Email sent to {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send SMS
     */
    protected function sendSMS(User $user, string $message, array $data = [], ?User $kine = null)
    {
        $preferences = $this->getPreferences($user);
        $phone = $preferences->getEffectivePhoneNumber();

        if (!$phone) {
            return false;
        }

        try {
            // Get sender number (kine's phone if available)
            $senderNumber = $kine?->phone ?? env('SMS_FROM', 'LeHubKine');

            if (env('SMS_PROVIDER') === 'twilio') {
                $twilio = new \Twilio\Rest\Client(
                    env('TWILIO_SID'),
                    env('TWILIO_AUTH_TOKEN')
                );

                $twilio->messages->create(
                    $phone,
                    [
                        'from' => $senderNumber,
                        'body' => $message
                    ]
                );
            }

            Log::info("SMS sent to {$phone}");
            return true;
        } catch (\Exception $e) {
            Log::error("SMS failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send WhatsApp
     */
    protected function sendWhatsApp(User $user, string $message, array $data = [], ?User $kine = null)
    {
        if (!env('WHATSAPP_ENABLED')) {
            return false;
        }

        $preferences = $this->getPreferences($user);
        $phone = $preferences->getEffectiveWhatsappNumber();

        if (!$phone) {
            return false;
        }

        try {
            $senderNumber = $kine?->phone ?? env('TWILIO_WHATSAPP_FROM', '+14155238886');

            if (env('WHATSAPP_PROVIDER') === 'twilio') {
                $twilio = new \Twilio\Rest\Client(
                    env('TWILIO_SID'),
                    env('TWILIO_AUTH_TOKEN')
                );

                $twilio->messages->create(
                    "whatsapp:{$phone}",
                    [
                        'from' => "whatsapp:{$senderNumber}",
                        'body' => $message
                    ]
                );
            }

            Log::info("WhatsApp sent to {$phone}");
            return true;
        } catch (\Exception $e) {
            Log::error("WhatsApp failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send push notification
     */
    protected function sendPushNotification(User $user, string $type, string $title, string $message, array $data = [], string $actionUrl = null, string $priority = 'medium')
    {
        try {
            $notification = Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'metadata' => $data,
                'action_url' => $actionUrl,
                'is_read' => false,
            ]);

            broadcast(new NewNotification($notification))->toOthers();

            return $notification;
        } catch (\Exception $e) {
            Log::error("Push failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or create notification preferences
     */
    protected function getPreferences(User $user): NotificationPreference
    {
        return $user->notificationPreference ?? NotificationPreference::create(['user_id' => $user->id]);
    }

    /**
     * Check if we should send for this type based on preferences
     */
    protected function shouldSendForType(string $type, string $channel, NotificationPreference $preferences): bool
    {
        return match(true) {
            str_contains($type, 'appointment.reminder') => $preferences->appointment_reminders,
            str_contains($type, 'appointment.cancelled') => $preferences->appointment_cancellations,
            str_contains($type, 'appointment.confirmed') => $preferences->appointment_confirmations,
            str_contains($type, 'exercise') => $preferences->exercise_reminders,
            default => true,
        };
    }

    /**
     * Check daily notification limit
     */
    protected function checkDailyLimit(User $user, NotificationPreference $preferences): bool
    {
        $key = $this->dailyCountKey . $user->id . '_' . now()->format('Y-m-d');
        $count = Cache::get($key, 0);

        return $count < $preferences->max_daily_notifications;
    }

    /**
     * Increment daily notification count
     */
    protected function incrementDailyCount(User $user): void
    {
        $key = $this->dailyCountKey . $user->id . '_' . now()->format('Y-m-d');
        Cache::increment($key);
        Cache::expire($key, now()->endOfDay());
    }

    /**
     * Format SMS message (shorter)
     */
    protected function formatSMSMessage(string $message): string
    {
        // Truncate to 160 characters for SMS
        return strlen($message) > 160 ? substr($message, 0, 157) . '...' : $message;
    }

    /**
     * Get reminder message based on type
     */
    protected function getReminderMessage(string $type, int $hoursBefore, string $date, string $time, Appointment $appointment, ?User $kine = null): array
    {
        $location = $appointment->location === 'online' ? 'en ligne' : 'au cabinet';
        $kineName = $kine ? $kine->full_name : 'votre kinésithérapeute';

        return match($type) {
            '24h' => [
                'title' => 'Rappel: Rendez-vous demain',
                'message' => "Bonjour! Nous vous rappelons votre rendez-vous demain à {$time} {$location} avec {$kineName}.",
            ],
            '2h' => [
                'title' => 'Rappel: Rendez-vous dans 2 heures',
                'message' => "Votre rendez-vous avec {$kineName} commence dans 2 heures ({$time}). Préparez-vous !",
            ],
            '1h' => [
                'title' => 'Rappel: Rendez-vous dans 1 heure',
                'message' => "Votre rendez-vous avec {$kineName} commence dans 1 heure ({$time}).",
            ],
            default => [
                'title' => 'Rappel de rendez-vous',
                'message' => "Vous avez un rendez-vous le {$date} à {$time} {$location} avec {$kineName}.",
            ],
        };
    }
}
