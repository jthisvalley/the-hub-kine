<?php

use Illuminate\Support\Facades\Broadcast;

// Private channel for user notifications
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Private channel for user appointments
Broadcast::channel('appointments.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
