<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentReminder implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $appointment;
    public $reminderType;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, Appointment $appointment, string $reminderType)
    {
        $this->user = $user;
        $this->appointment = $appointment;
        $this->reminderType = $reminderType;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('appointments.' . $this->user->id),
        ];

        if ($this->appointment->patient_id && $this->appointment->patient_id !== $this->user->id) {
            $channels[] = new Channel('appointments.' . $this->appointment->patient_id);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'appointment.reminder';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $patient = $this->appointment->patient;
        $kine = $this->appointment->slot->kine;

        $data = [
            'appointment_id' => $this->appointment->id,
            'reminder_type' => $this->reminderType,
            'start_time' => $this->appointment->slot->start_time->toISOString(),
            'end_time' => $this->appointment->slot->end_time->toISOString(),
            'type' => $this->appointment->type,
            'is_online' => $this->appointment->is_online,
            'video_link' => $this->appointment->video_link,
            'status' => $this->appointment->status,
        ];

        if ($this->user->role === 'kine') {
            $data['kine_name'] = $this->user->first_name . ' ' . $this->user->last_name;
            $data['patient_name'] = $patient ? $patient->first_name . ' ' . $patient->last_name : 'Patient';
        } else {
            $data['patient_name'] = $this->user->first_name . ' ' . $this->user->last_name;
            $data['kine_name'] = $kine ? $kine->first_name . ' ' . $kine->last_name : 'Kinésithérapeute';
        }

        return $data;
    }
}
