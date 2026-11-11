<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each account has its language, kept on the server: the emails it receives are written in it.
 * People without an account get the default language of the instance.
 */
class UserLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true], $attributes));
    }

    public function test_the_application_speaks_seven_languages(): void
    {
        $this->assertSame(['en', 'fr', 'de', 'es', 'it', 'nl', 'pt'], Locales::SUPPORTED);
    }

    public function test_an_account_without_a_language_uses_the_default_language(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'fr']);
        app(SettingsService::class)->clearCache();

        $this->assertSame('fr', Locales::forUser($this->user()));
        $this->assertSame('fr', Locales::forUser(null));
    }

    public function test_an_account_uses_its_own_language(): void
    {
        $this->assertSame('es', Locales::forUser($this->user(['language' => 'es'])));
    }

    public function test_an_unknown_default_falls_back_on_english(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'xx']);
        app(SettingsService::class)->clearCache();

        $this->assertSame('en', Locales::default());
    }

    public function test_an_account_saves_its_language(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/users/me', ['language' => 'de'])->assertOk();

        $this->assertSame('de', $user->fresh()->language);
        $this->assertSame('de', $this->actingAs($user->fresh(), 'api')->getJson('/api/users/me')->json('data.user.language'));
    }

    public function test_only_a_language_of_the_application_can_be_saved(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/users/me', ['language' => 'xx'])->assertStatus(400);

        $this->assertNull($user->fresh()->language);
    }

    public function test_signing_up_keeps_the_language_of_the_screen(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Setting::where('key', 'self_registration_enabled')->update(['value' => 'true']);

        $this->postJson('/api/auth/register', [
            'name' => 'Lucía', 'email' => 'lucia@example.test', 'language' => 'es',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertSame('es', User::where('email', 'lucia@example.test')->value('language'));
    }
}
