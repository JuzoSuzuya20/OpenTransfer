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
use App\Models\ReverseShareInvite;
use App\Models\Setting;

class reverseShareInviteMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    /**
     * Create a new message instance.
     */
    public $share;
    public $recipient;
    public $user;
    public $recipient_name;
    public $sender_name;
    public $token;
    public $invite;
    public $isExistingUser;

    public function __construct(User $user, ReverseShareInvite $invite, $token, $isExistingUser = false)
    {
        $this->user = $user;
        $this->invite = $invite;
        $this->token = $token;
        $this->isExistingUser = $isExistingUser;
        $this->recipient_name = explode(' ', $invite->recipient_name)[0];
        $this->sender_name = explode(' ', $user->name)[0];
        $this->speak(Locales::forEmail($invite->recipient_email));
    }

    protected function templateName(): string
    {
        return 'reverseShareInviteMailV2';
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
