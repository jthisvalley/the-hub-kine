<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';
    case PUSH = 'push';
    case ALL = 'all';

    public function label(): string
    {
        return match($this) {
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::WHATSAPP => 'WhatsApp',
            self::PUSH => 'Notification push',
            self::ALL => 'Tous les canaux',
        };
    }
}
