<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizedEmail;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Setting;

class emailVerificationMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    public $code;
    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct($code, User $user)
    {
        $this->code = $code;
        $this->user = $user;
        $this->speak(Locales::forUser($user));
    }

    protected function templateName(): string
    {
        return 'emailVerificationMail';
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}



