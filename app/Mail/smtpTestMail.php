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

    /**
     * The name of the server cut at its dots: BlueMind dresses a name of domain found in the text
     * its own way, white on the light grey of its theme, whatever the colour the email gives it.
     * The template joins the parts with ".<wbr>", which no copy keeps; each part stays escaped.
     *
     * @var list<string>
     */
    public array $hostParts = [];

    public function __construct(public User $user, public string $host)
    {
        $this->hostParts = explode('.', $host);
        $this->speak(Locales::forUser($user));
    }

    protected function templateName(): string
    {
        return 'smtpTestMail';
    }
}
