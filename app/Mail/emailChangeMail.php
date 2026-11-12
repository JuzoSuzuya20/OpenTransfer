<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizedEmail;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// The code that moves an account to a new address, sent to that address
class emailChangeMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    public $code;

    public $user;

    public function __construct($code, User $user)
    {
        $this->code = $code;
        $this->user = $user;
        $this->speak(Locales::forUser($user));
    }

    protected function templateName(): string
    {
        return 'emailChangeMail';
    }

    public function attachments(): array
    {
        return [];
    }
}
