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
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every email is written in the language of its reader: an account reads its own, someone without
 * an account the default language of the instance. They all wear the look of the application.
 */
class EmailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            File::delete($path);
        }
        $this->artisan('view:clear');
        parent::tearDown();
    }

    private function defaultLanguage(string $locale): void
    {
        Setting::where('key', 'default_language')->update(['value' => $locale]);
        app(SettingsService::class)->clearCache();
    }

    private function owner(?string $language = null): User
    {
        return User::factory()->create(['name' => 'Camille Martin', 'admin' => false, 'active' => true, 'is_guest' => false, 'language' => $language]);
    }

    private function share(User $owner): Share
    {
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier marché travaux', 'path' => 'tests-'.Str::random(8),
            'long_id' => 'test-'.Str::lower(Str::random(12)), 'size' => 11, 'file_count' => 3, 'download_count' => 2,
            'expires_at' => now()->addDays(3)->addHour(), 'status' => 'ready',
        ]);
        // The file count of a share is the count of its files
        foreach (['plans.pdf', 'cctp.docx', 'photos.zip'] as $name) {
            \App\Models\File::create(['name' => $name, 'original_name' => $name, 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);
        }

        return $share->fresh();
    }

    private function mails(): array
    {
        $owner = $this->owner();
        $share = $this->share($owner);
        $invite = ReverseShareInvite::create([
            'user_id' => $owner->id, 'recipient_name' => 'Dominique Durand', 'recipient_email' => 'dominique@example.test',
            'message' => 'Merci de déposer le contrat signé', 'expires_at' => now()->addDays(7),
        ]);

        return [
            new accountCreatedMail('token-123', $owner),
            new emailVerificationMail('123456', $owner),
            new passwordResetMail('token-123', $owner),
            new reverseShareInviteMail($owner, $invite, 'token-123', false),
            new shareCreatedMail($owner, $share, ['name' => 'Dominique Durand', 'email' => 'dominique@example.test']),
            new shareDeletedWarningMail($share),
            new shareDeletionWarningMail($share),
            new shareDownloadedMail($share),
            new shareExpiredWarningMail($share),
            new shareExpiryWarningMail($share),
        ];
    }

    public function test_every_email_is_written_in_every_language(): void
    {
        foreach (Locales::SUPPORTED as $locale) {
            $this->defaultLanguage($locale);
            foreach ($this->mails() as $mail) {
                $html = $mail->render();
                $subject = $mail->envelope()->subject;

                $this->assertSame($locale, $mail->emailLocale, get_class($mail));
                $this->assertStringContainsString("lang=\"$locale\"", $html, get_class($mail)." in $locale");
                $this->assertNotEmpty(trim((string) $subject), get_class($mail)." has no subject in $locale");
                $this->assertStringNotContainsString('{{', $html, get_class($mail)." left a Twig tag in $locale");
            }
        }
    }

    public function test_someone_without_an_account_reads_the_default_language(): void
    {
        $this->defaultLanguage('fr');
        $sender = $this->owner('es');
        $mail = new shareCreatedMail($sender, $this->share($sender), ['name' => 'Dominique Durand', 'email' => 'dominique@example.test']);

        $this->assertSame('fr', $mail->emailLocale);
        $this->assertStringContainsString('Camille vous a envoyé', $mail->render());
    }

    public function test_a_recipient_with_an_account_reads_its_own_language(): void
    {
        $this->defaultLanguage('fr');
        $sender = $this->owner();
        User::factory()->create(['email' => 'lucia@example.test', 'language' => 'es']);

        $mail = new shareCreatedMail($sender, $this->share($sender), ['name' => 'Lucía', 'email' => 'lucia@example.test']);

        $this->assertSame('es', $mail->emailLocale);
    }

    public function test_the_owner_of_a_share_reads_its_own_language(): void
    {
        $this->defaultLanguage('fr');
        $mail = new shareDownloadedMail($this->share($this->owner('de')));

        $this->assertSame('de', $mail->emailLocale);
        $this->assertStringContainsString('Dossier marché travaux', $mail->render());
    }

    public function test_durations_are_written_in_the_language_of_the_email(): void
    {
        $this->defaultLanguage('fr');

        $this->assertStringContainsString('3 jours', (new shareExpiryWarningMail($this->share($this->owner())))->render());
    }

    public function test_the_email_wears_the_look_of_the_application(): void
    {
        $html = (new shareDownloadedMail($this->share($this->owner())))->render();

        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('#161616', $html);
        $this->assertStringContainsString('#6a6af4', $html);
    }

    public function test_a_template_an_admin_customised_before_still_serves_the_default_language(): void
    {
        $this->defaultLanguage('fr');
        // Twig names its compiled classes after the template: a template no other test rewrites
        $path = storage_path('templates/emails/shareExpiryWarningMail.twig');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "{% extends 'emails/layout' %}{% block header %}Header{% endblock %}{% block content %}Modèle maison {{ share.name }}{% endblock %}");
        $this->written[] = $path;

        $share = $this->share($this->owner());
        $this->assertStringContainsString('Modèle maison Dossier marché travaux', (new shareExpiryWarningMail($share))->render());

        // Another language keeps the shipped text
        $this->assertStringNotContainsString('Modèle maison', (new shareExpiryWarningMail($this->share($this->owner('de'))))->render());
    }

    public function test_a_subject_an_admin_customised_before_still_serves_the_default_language(): void
    {
        $this->defaultLanguage('fr');
        Setting::where('key', 'email_subject_shareDownloadedMail.twig')->update(['value' => 'Votre dossier a été récupéré']);

        $this->assertSame('Votre dossier a été récupéré', (new shareDownloadedMail($this->share($this->owner())))->envelope()->subject);
        $this->assertNotSame('Votre dossier a été récupéré', (new shareDownloadedMail($this->share($this->owner('de'))))->envelope()->subject);
    }

    public function test_an_admin_can_rewrite_the_email_of_one_language(): void
    {
        $path = storage_path('templates/emails/es/shareDownloadedMail.twig');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "{% extends 'emails/layout' %}{% block subject %}Asunto propio{% endblock %}{% block header %}H{% endblock %}{% block content %}Texto propio{% endblock %}");
        $this->written[] = $path;

        $mail = new shareDownloadedMail($this->share($this->owner('es')));

        $this->assertSame('Asunto propio', $mail->envelope()->subject);
        $this->assertStringContainsString('Texto propio', $mail->render());
    }
}
