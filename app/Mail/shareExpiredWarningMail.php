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
use App\Models\Share;
use App\Models\Setting;
class shareExpiredWarningMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    /**
     * Create a new message instance.
     */
    public $share;

    public function __construct(Share $share)
    {
        $this->share = $share;
        $this->speak(Locales::forUser($share->user));
    }

    protected function templateName(): string
    {
        return 'shareExpiredWarningMail';
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
