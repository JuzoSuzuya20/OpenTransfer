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
class accountCreatedMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    public $token;
    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct($token, User $user)
    {
        $this->token = $token;
        $this->user = $user;
        $this->speak(Locales::forUser($user));
    }

    protected function templateName(): string
    {
        return 'accountCreatedMail';
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
