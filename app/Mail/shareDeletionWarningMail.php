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
class shareDeletionWarningMail extends Mailable
{
    use Queueable, SerializesModels, LocalizedEmail;

    /**
     * Create a new message instance.
     */
    public $share;
    public $deletion_date;
    public $deletes_in;

    public function __construct(Share $share)
    {
        $this->share = $share;
        $this->speak(Locales::forUser($share->user));
        $this->deletion_date = $share->expires_at;
        $this->deletes_in = $share->expires_at->copy()->locale($this->emailLocale)->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
    }

    protected function templateName(): string
    {
        return 'shareDeletionWarningMail';
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
