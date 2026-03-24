<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        // Email notifications
        'email_notifications',
        'appointment_reminders',
        'appointment_cancellations',
        'appointment_confirmations',
        'exercise_reminders',
        'marketing_emails',

        // SMS notifications
        'sms_notifications',
        'phone_number',

        // WhatsApp notifications
        'whatsapp_notifications',
        'whatsapp_number',

        // Push notifications
        'push_notifications',

        // Schedule settings
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'max_daily_notifications',
        'notification_priority',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'appointment_reminders' => 'boolean',
        'appointment_cancellations' => 'boolean',
        'appointment_confirmations' => 'boolean',
        'exercise_reminders' => 'boolean',
        'marketing_emails' => 'boolean',
        'sms_notifications' => 'boolean',
        'whatsapp_notifications' => 'boolean',
        'push_notifications' => 'boolean',
        'quiet_hours_enabled' => 'boolean',
        'max_daily_notifications' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getEffectivePhoneNumber(): ?string
    {
        return $this->phone_number ?? $this->user->phone;
    }

    public function getEffectiveWhatsappNumber(): ?string
    {
        return $this->whatsapp_number ?? $this->phone_number ?? $this->user->phone;
    }

    public function isQuietHours(): bool
    {
        if (!$this->quiet_hours_enabled || !$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }

        $now = now();
        $start = now()->setTimeFromTimeString($this->quiet_hours_start);
        $end = now()->setTimeFromTimeString($this->quiet_hours_end);

        // Handle overnight quiet hours
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    public function canSendNotification(string $type, string $priority = 'medium'): bool
    {
        // Check quiet hours
        if ($this->isQuietHours() && $priority !== 'high') {
            return false;
        }

        // Check priority filter
        if ($this->notification_priority === 'high' && $priority !== 'high') {
            return false;
        }

        if ($this->notification_priority === 'medium' && !in_array($priority, ['high', 'medium'])) {
            return false;
        }

        return true;
    }
}
