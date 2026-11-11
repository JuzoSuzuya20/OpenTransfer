<?php

namespace Tests\Feature;

use App\Mail\accountCreatedMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin rewrites the emails one language at a time. What the admin writes for a language is
 * kept apart from the shipped templates, can be reset, and only changes the emails of that language.
 */
class EmailTemplatesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('templates/emails/es'));
        File::deleteDirectory(storage_path('templates/emails/it'));
        File::delete(storage_path('templates/emails/passwordResetMail.twig'));
        $this->artisan('view:clear');
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true]);
    }

    private function templates(?string $locale = null): array
    {
        $query = $locale ? "?locale=$locale" : '';

        return collect($this->actingAs($this->admin(), 'api')->getJson("/api/email-templates$query")->assertOk()->json('data.templates'))
            ->keyBy('id')->all();
    }

    public function test_the_ten_emails_are_listed_in_the_language_asked(): void
    {
        $templates = $this->templates('es');

        $this->assertCount(10, $templates);
        $this->assertSame('{{ sender_name }} le ha enviado archivos', $templates['shareCreatedMail']['subject']);
        $this->assertSame('Archivos para descargar', $templates['shareCreatedMail']['variables']['header']);
        $this->assertFalse($templates['shareCreatedMail']['customised']);
    }

    public function test_without_a_language_the_default_one_is_listed(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'de']);
        app(SettingsService::class)->clearCache();

        $this->assertSame('Konto erstellt', $this->templates()['accountCreatedMail']['variables']['header']);
    }

    public function test_rewriting_a_language_changes_the_emails_of_that_language_only(): void
    {
        $template = $this->templates('es')['accountCreatedMail'];
        $template['subject'] = 'Bienvenido a {{ settings.application_name }}';
        $template['variables']['header'] = 'Bienvenida';
        $template['variables']['content'] = '<p>Texto de la casa para {{ user.name }}</p>';

        $this->actingAs($this->admin(), 'api')->putJson('/api/email-templates', ['locale' => 'es', 'templates' => [$template]])->assertOk();

        $spanish = new accountCreatedMail('token', User::factory()->create(['name' => 'Lucía', 'language' => 'es']));
        $this->assertStringContainsString('Texto de la casa para Lucía', $spanish->render());
        $this->assertStringStartsWith('Bienvenido a', $spanish->envelope()->subject);
        $this->assertTrue($this->templates('es')['accountCreatedMail']['customised']);

        $italian = new accountCreatedMail('token', User::factory()->create(['name' => 'Luca', 'language' => 'it']));
        $this->assertStringNotContainsString('Texto de la casa', $italian->render());
        $this->assertStringNotContainsString('Texto de la casa', File::get(resource_path('views/emails/es/accountCreatedMail.twig')));
    }

    public function test_a_rewritten_language_can_go_back_to_the_shipped_text(): void
    {
        $template = $this->templates('it')['accountCreatedMail'];
        $template['variables']['header'] = 'Benvenuto';
        $this->actingAs($this->admin(), 'api')->putJson('/api/email-templates', ['locale' => 'it', 'templates' => [$template]])->assertOk();

        $this->actingAs($this->admin(), 'api')->deleteJson('/api/email-templates/it/accountCreatedMail')->assertOk();

        $this->assertSame('Account creato', $this->templates('it')['accountCreatedMail']['variables']['header']);
    }

    public function test_a_template_rewritten_before_languages_shows_for_the_default_language(): void
    {
        File::ensureDirectoryExists(storage_path('templates/emails'));
        File::put(storage_path('templates/emails/passwordResetMail.twig'), "{% extends 'emails/layout' %}{% block header %}Maison{% endblock %}{% block content %}Corps{% endblock %}");
        Setting::where('key', 'email_subject_passwordResetMail.twig')->update(['value' => 'Objet maison']);

        $template = $this->templates('en')['passwordResetMail'];

        $this->assertSame('Maison', $template['variables']['header']);
        $this->assertSame('Objet maison', $template['subject']);
        $this->assertTrue($template['customised']);
    }

    public function test_resetting_the_default_language_also_drops_what_was_written_before_languages(): void
    {
        File::ensureDirectoryExists(storage_path('templates/emails'));
        File::put(storage_path('templates/emails/passwordResetMail.twig'), "{% extends 'emails/layout' %}{% block header %}Maison{% endblock %}{% block content %}Corps{% endblock %}");
        Setting::where('key', 'email_subject_passwordResetMail.twig')->update(['value' => 'Objet maison']);

        $this->actingAs($this->admin(), 'api')->deleteJson('/api/email-templates/en/passwordResetMail')->assertOk();

        $this->assertFileDoesNotExist(storage_path('templates/emails/passwordResetMail.twig'));
        $template = $this->templates('en')['passwordResetMail'];
        $this->assertFalse($template['customised']);
        $this->assertSame('Reset your password', $template['subject']);
    }

    public function test_only_a_language_of_the_application_and_a_known_email_can_be_written(): void
    {
        $template = $this->templates('es')['accountCreatedMail'];

        $this->actingAs($this->admin(), 'api')->putJson('/api/email-templates', ['locale' => 'xx', 'templates' => [$template]])->assertStatus(422);
        $template['id'] = '../../evil';
        $this->actingAs($this->admin(), 'api')->putJson('/api/email-templates', ['locale' => 'es', 'templates' => [$template]])->assertStatus(400);
        $this->actingAs($this->admin(), 'api')->deleteJson('/api/email-templates/es/..%2F..%2Fevil')->assertStatus(404);
    }

    public function test_a_block_the_email_needs_cannot_be_emptied(): void
    {
        $template = $this->templates('es')['accountCreatedMail'];
        $template['variables']['action_url'] = '';

        $this->actingAs($this->admin(), 'api')->putJson('/api/email-templates', ['locale' => 'es', 'templates' => [$template]])->assertStatus(422);
    }
}
