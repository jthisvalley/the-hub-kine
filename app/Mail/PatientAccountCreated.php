<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class PatientAccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;
    public $kine;

    public function __construct(User $user, $password)
    {
        $this->user = $user;
        $this->password = $password;
        $this->kine = auth()->user();
    }

    public function build()
    {
        return $this->subject('Votre compte a été créé - Votre Kinésithérapeute')
                    ->markdown('emails.patient.account-created')
                    ->with([
                        'user' => $this->user,
                        'password' => $this->password,
                        'kine' => $this->kine,
                    ]);
    }
}
