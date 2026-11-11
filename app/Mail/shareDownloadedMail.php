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
class shareDownloadedMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;
    public $share;
    /**
     * Create a new message instance.
     */
    public function __construct(Share $share)
    {
        $this->share = $share;
        $this->speak(Locales::forUser($share->user));
    }

    protected function templateName(): string
    {
        return 'shareDownloadedMail';
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
