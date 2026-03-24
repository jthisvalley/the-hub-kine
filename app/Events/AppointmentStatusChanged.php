<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $appointment;
    public $oldStatus;
    public $newStatus;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, Appointment $appointment, string $oldStatus, string $newStatus)
    {
        $this->user = $user;
        $this->appointment = $appointment;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('appointments.' . $this->user->id), 
        ];

        // Add patient channel if appointment has a patient
        if ($this->appointment->patient_id) {
            $channels[] = new Channel('appointments.' . $this->appointment->patient_id);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'appointment.status_changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'patient_name' => $this->appointment->patient ?
                $this->appointment->patient->first_name . ' ' . $this->appointment->patient->last_name : 'Patient',
            'kine_name' => $this->user->first_name . ' ' . $this->user->last_name,
            'start_time' => $this->appointment->slot->start_time->toISOString(),
            'updated_at' => now()->toISOString(),
        ];
    }
}
