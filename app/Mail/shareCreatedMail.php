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
use App\Models\Share;
use App\Models\Setting;
class shareCreatedMail extends Mailable
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

    public function __construct(User $user, Share $share, $recipient)
    {
        $this->user = $user;
        $this->share = $share;
        $this->recipient = $recipient;
        $this->recipient_name = explode(' ', $recipient['name'])[0];
        $this->sender_name = explode(' ', $user->name)[0];
        $this->speak(Locales::forEmail($recipient['email'] ?? null));
    }

    protected function templateName(): string
    {
        return 'shareCreatedMail';
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
