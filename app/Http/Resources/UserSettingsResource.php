<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'theme' => $this->theme,
            'language' => $this->language,
            'timezone' => $this->timezone,
            'email_notifications' => $this->email_notifications,
            'sms_notifications' => $this->sms_notifications,
            'push_notifications' => $this->push_notifications,
            'reminder_hours_before' => $this->reminder_hours_before,
            'auto_confirm_appointments' => $this->auto_confirm_appointments,
            'share_progress_with_kine' => $this->share_progress_with_kine,
            'data_sharing_preferences' => $this->data_sharing_preferences,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
