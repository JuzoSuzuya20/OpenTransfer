<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Mail\passwordResetMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Until an administrator sets up the mail server, the e-mails of the app cannot leave. Each one
// failed three times with an error and a stack trace in the log (no host, or the sender address
// "null"), then piled up in the failed jobs. It is now set aside with one line saying why.
class MailWithoutServerTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function smtp(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::where('key', $key)->update(['value' => $value]);
        }
        app(SettingsService::class)->clearCache();
    }

    private function job(): sendEmail
    {
        $user = User::factory()->create();

        return new sendEmail($user->email, passwordResetMail::class, ['token' => 'token-123', 'user' => $user]);
    }

    public function test_without_a_mail_server_an_email_is_set_aside_with_a_reason(): void
    {
        $this->smtp(['smtp_host' => '', 'smtp_sender_address' => 'partage@entreprise.example']);
        Log::spy();

        $this->job()->handle();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'mail server'))->once();
    }

    public function test_without_a_sender_address_an_email_is_set_aside_too(): void
    {
        $this->smtp(['smtp_host' => 'smtp.entreprise.example', 'smtp_sender_address' => 'null']);
        Log::spy();

        $this->job()->handle();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'sender address'))->once();
    }
}
