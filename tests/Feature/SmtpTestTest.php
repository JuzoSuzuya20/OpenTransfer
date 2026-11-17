<?php

namespace Tests\Feature;

use App\Mail\smtpTestMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailConfigService;
use App\Services\SettingsService;
use App\Support\Secrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Administration > Serveur d'e-mails: a button sends a test email to the admin, with the values of
 * the form (saved or not), at once, and tells what went wrong when it fails. The password of the
 * form left empty is the one saved, as everywhere else; it never shows in the answer.
 */
class SmtpTestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** @var array<int, array> the settings each test mailer was built with */
    private array $built = [];

    private ?\Throwable $failure = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The mailer the test builds: in memory, or failing as a real server would
        $this->app->bind(EmailConfigService::class, function ($app, $params) {
            $test = $this;

            return new class($params['settings'] ?? null, $test) extends EmailConfigService {
                public function __construct(?array $settings, private $test)
                {
                    parent::__construct($settings);
                }

                public function configureMailer(?int $timeout = null)
                {
                    $this->test->recordBuilt($this->settingsInUse());
                    if ($failure = $this->test->failure()) {
                        throw $failure;
                    }

                    return Mail::mailer('array');
                }
            };
        });
        $this->saveSmtp(['smtp_host' => 'smtp.saved.test', 'smtp_port' => '587', 'smtp_username' => 'relay@example.test',
            'smtp_encryption' => 'tls', 'smtp_sender_address' => 'noreply@example.test', 'smtp_sender_name' => 'Drop Me']);
        Setting::where('key', 'smtp_password')->update(['value' => Secrets::seal('the-saved-password')]);
        app(SettingsService::class)->clearCache();
    }

    public function recordBuilt(array $settings): void
    {
        $this->built[] = $settings;
    }

    public function failure(): ?\Throwable
    {
        return $this->failure;
    }

    private function saveSmtp(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::where('key', $key)->update(['value' => $value]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille@example.test', 'admin' => true,
            'active' => true, 'must_change_password' => false, 'is_guest' => false, 'language' => 'fr']);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'smtp_host' => 'smtp.saved.test', 'smtp_port' => 587, 'smtp_username' => 'relay@example.test', 'smtp_password' => '',
            'smtp_encryption' => 'tls', 'smtp_sender_address' => 'noreply@example.test', 'smtp_sender_name' => 'Drop Me',
        ], $overrides);
    }

    private function sentMessages()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    public function test_an_admin_gets_a_test_email_at_once(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/settings/smtp/test', $this->form())
            ->assertOk()->assertJsonPath('data.sent_to', 'camille@example.test');

        $this->assertCount(1, $this->sentMessages());
        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame('camille@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
        $this->assertStringContainsString('Bonjour Camille,', $email->getHtmlBody());
        $this->assertStringContainsString('smtp.<wbr>saved.<wbr>test', $email->getHtmlBody());
    }

    public function test_the_values_of_the_form_are_tested_before_they_are_saved(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/settings/smtp/test', $this->form(['smtp_host' => 'smtp.new.test', 'smtp_encryption' => '']))->assertOk();

        $this->assertSame('smtp.new.test', $this->built[0]['host']);
        // "Aucun" chosen in the form, not the TLS saved
        $this->assertSame('', $this->built[0]['encryption']);
        $this->assertSame('smtp.saved.test', Setting::where('key', 'smtp_host')->value('value'));
    }

    public function test_an_empty_password_tests_the_one_saved(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'api')->postJson('/api/settings/smtp/test', $this->form(['smtp_password' => '']))->assertOk();
        $this->assertSame('the-saved-password', $this->built[0]['password']);

        $this->actingAs($admin, 'api')->postJson('/api/settings/smtp/test', $this->form(['smtp_password' => 'a-new-password']))->assertOk();
        $this->assertSame('a-new-password', $this->built[1]['password']);
    }

    public function test_a_failure_tells_why_without_the_password(): void
    {
        $this->failure = new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed for the-saved-password".');

        $response = $this->actingAs($this->admin(), 'api')->postJson('/api/settings/smtp/test', $this->form())
            ->assertUnprocessable()->assertJsonPath('code', 'smtp_test_failed');

        $this->assertStringContainsString('535 5.7.8 Authentication failed', $response->json('data.reason'));
        $this->assertStringNotContainsString('the-saved-password', $response->getContent());
    }

    public function test_nothing_set_up_is_said_so(): void
    {
        $this->actingAs($this->admin(), 'api')->postJson('/api/settings/smtp/test', $this->form(['smtp_host' => '']))
            ->assertUnprocessable()->assertJsonPath('code', 'smtp_not_configured');

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_only_an_admin_sends_a_test_email(): void
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false]);

        $this->actingAs($member, 'api')->postJson('/api/settings/smtp/test', $this->form())->assertForbidden();
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_the_test_email_is_not_a_template_to_customise(): void
    {
        $this->assertFileExists(resource_path('views/emails/fr/smtpTestMail.twig'));

        $listing = $this->actingAs($this->admin(), 'api')->getJson('/api/email-templates')->assertOk();

        $this->assertStringNotContainsString('smtpTestMail', $listing->getContent());
    }

    public function test_the_test_email_reads_in_every_language(): void
    {
        foreach (['en', 'fr', 'de', 'es', 'it', 'nl', 'pt'] as $language) {
            $this->assertFileExists(resource_path("views/emails/{$language}/smtpTestMail.twig"), $language);
        }
        $html = (new smtpTestMail($this->admin(), 'smtp.saved.test'))->render();

        $this->assertStringContainsString('Bonjour Camille,', $html);
        $this->assertStringContainsString('smtp.<wbr>saved.<wbr>test', $html);
    }
}
