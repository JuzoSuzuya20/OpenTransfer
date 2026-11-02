<?php

namespace Tests\Feature;

use App\Mail\accountCreatedMail;
use App\Mail\emailVerificationMail;
use App\Mail\passwordResetMail;
use App\Mail\reverseShareInviteMail;
use App\Mail\shareCreatedMail;
use App\Mail\shareDeletedWarningMail;
use App\Mail\shareDeletionWarningMail;
use App\Mail\shareDownloadedMail;
use App\Mail\shareExpiredWarningMail;
use App\Mail\shareExpiryWarningMail;
use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use Twig\Sandbox\SecurityError;

/**
 * Les admins peuvent réécrire les modèles d'e-mail. Sans sandbox, un modèle
 * comme {{ ['id']|map('system') }} exécutait des commandes sur le serveur.
 */
class EmailTemplateSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $customTemplates = [];

    protected function tearDown(): void
    {
        foreach ($this->customTemplates as $path) {
            @unlink($path);
        }
        $this->artisan('view:clear');
        parent::tearDown();
    }

    private function share(): Share
    {
        $owner = User::factory()->create(['name' => 'Jane Doe', 'admin' => false, 'active' => true, 'is_guest' => false]);

        return Share::create([
            'user_id' => $owner->id,
            'name' => 'Quarterly report',
            'path' => 'tests-'.Str::random(8),
            'long_id' => 'test-'.Str::lower(Str::random(12)),
            'size' => 11,
            'file_count' => 2,
            'download_count' => 1,
            'expires_at' => now()->addDays(3),
            'status' => 'ready',
        ])->fresh();
    }

    private function writeCustomTemplate(string $name, string $content): void
    {
        $path = storage_path("templates/emails/{$name}.twig");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "{% extends 'emails/layout' %}{% block header %}Header{% endblock %}{% block content %}{$content}{% endblock %}");
        $this->customTemplates[] = $path;
        // Twig nomme ses classes compilées d'après le chemin du modèle : chaque test
        // réécrit donc un modèle différent, sinon la classe déjà chargée resservirait.
    }

    public function test_every_default_email_still_renders_inside_the_sandbox(): void
    {
        $share = $this->share();
        $user = $share->user;
        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'recipient_name' => 'John Smith',
            'recipient_email' => 'john@example.test',
            'message' => 'Please send the signed contract',
            'expires_at' => now()->addDays(7),
        ]);

        $mails = [
            new accountCreatedMail('token-123', $user),
            new emailVerificationMail('123456', $user),
            new passwordResetMail('token-123', $user),
            new reverseShareInviteMail($user, $invite, 'token-123', false),
            new shareCreatedMail($user, $share, ['name' => 'John Smith', 'email' => 'john@example.test']),
            new shareDeletedWarningMail($share),
            new shareDeletionWarningMail($share),
            new shareDownloadedMail($share),
            new shareExpiredWarningMail($share),
            new shareExpiryWarningMail($share),
        ];

        foreach ($mails as $mail) {
            $html = $mail->render();
            $this->assertNotEmpty($html, get_class($mail).' rendered nothing');
        }
        $this->assertStringContainsString('Quarterly report', (new shareDownloadedMail($share))->render());
    }

    public function test_a_customised_template_can_use_share_and_owner_details(): void
    {
        $this->writeCustomTemplate('shareDownloadedMail', 'Downloaded {{ share.download_count }}x: {{ share.name|upper }} by {{ share.user.name }}');

        $html = (new shareDownloadedMail($this->share()))->render();

        $this->assertStringContainsString('Downloaded 1x: QUARTERLY REPORT by Jane Doe', $html);
    }

    public function test_a_template_cannot_execute_commands_on_the_server(): void
    {
        $marker = storage_path('framework/testing/sandbox-escape-'.Str::random(8));
        $this->writeCustomTemplate('shareExpiredWarningMail', "{{ ['touch {$marker}']|map('system')|join }}");

        try {
            (new shareExpiredWarningMail($this->share()))->render();
            $this->fail('A template calling system() must be refused.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(SecurityError::class, $e->getPrevious() ?? $e);
        } finally {
            $this->assertFileDoesNotExist($marker);
            @unlink($marker);
        }
    }

    public function test_a_template_cannot_read_the_application_configuration(): void
    {
        $this->writeCustomTemplate('shareDeletedWarningMail', "{{ config('app.key') }}");

        $this->expectException(\Throwable::class);
        (new shareDeletedWarningMail($this->share()))->render();
    }
}
