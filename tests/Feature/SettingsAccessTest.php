<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les routes de lecture des réglages sont ouvertes à tout compte connecté,
 * invités des dépôts inversés compris : les identifiants SMTP n'en sortent pas.
 */
class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SMTP_PASSWORD = 'smtp-password-of-the-company';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::where('key', 'smtp_password')->update(['value' => self::SMTP_PASSWORD]);
        Setting::where('key', 'smtp_username')->update(['value' => 'mailer@example.test']);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    public function test_a_regular_user_cannot_read_smtp_credentials_by_key(): void
    {
        foreach (['smtp_password', 'smtp_username'] as $key) {
            $response = $this->actingAs($this->user(), 'api')->getJson("/api/settings/{$key}");

            $response->assertNotFound();
            $this->assertStringNotContainsString(self::SMTP_PASSWORD, $response->getContent());
        }
    }

    public function test_a_guest_user_cannot_read_smtp_credentials(): void
    {
        $guest = $this->user(['is_guest' => true]);

        $response = $this->actingAs($guest, 'api')->getJson('/api/settings/smtp_password');

        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $response->getContent());
    }

    public function test_group_reads_hide_smtp_settings_from_regular_users(): void
    {
        foreach (['system.*', 'system.smtp'] as $group) {
            $response = $this->actingAs($this->user(), 'api')->getJson("/api/settings/group/{$group}")->assertOk();

            $this->assertStringNotContainsString(self::SMTP_PASSWORD, $response->getContent());
            $this->assertStringNotContainsString('smtp_', $response->getContent());
        }
    }

    public function test_regular_users_still_read_the_settings_their_screens_need(): void
    {
        $this->actingAs($this->user(), 'api')
            ->getJson('/api/settings/group/system.auth')
            ->assertOk()
            ->assertJsonFragment(['key' => 'self_registration_enabled']);
    }

    public function test_admins_can_still_read_smtp_settings(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->actingAs($admin, 'api')->getJson('/api/settings/smtp_password')
            ->assertOk()
            ->assertJsonPath('data.setting.value', self::SMTP_PASSWORD);
        $this->actingAs($admin, 'api')->getJson('/api/settings/group/system.*')
            ->assertOk()
            ->assertJsonFragment(['key' => 'smtp_password']);
    }
}
