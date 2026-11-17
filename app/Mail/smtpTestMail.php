<?php

namespace App\Mail;

use App\Mail\Concerns\LocalizedEmail;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Mail\Mailable;

// The e-mail an admin sends themselves to check the mail server (Administration > Serveur
// d'e-mails), in their language. Sent at once, never queued: the admin waits for the answer.
class smtpTestMail extends Mailable
{
    use LocalizedEmail;

    public function __construct(public User $user, public string $host)
    {
        $this->speak(Locales::forUser($user));
    }

    protected function templateName(): string
    {
        return 'smtpTestMail';
    }
}
