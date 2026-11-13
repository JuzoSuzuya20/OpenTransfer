<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The administration saves its page as the screen holds it: every setting of the groups it
// read, each value as text. On a fresh instance some settings are still empty (NULL), and the
// screen used to send them as the text "null": the IP allowlist and the SSO domains then read
// "null" as an invalid entry, and nothing could be saved at all.
class AdminSaveSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function bearer(): array
    {
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        return ['Authorization' => 'Bearer '.auth('api')->tokenById($admin->id)];
    }

    // What resources/js/api.js saveSettingsById() sends, from what the page loaded
    private function pageAsTheScreenSendsIt(array $headers, callable $text): array
    {
        $settings = [];
        foreach (['system.*', 'ui.*'] as $group) {
            foreach ($this->getJson('/api/settings/group/'.$group, $headers)->assertOk()->json('data.settings') as $setting) {
                $settings[] = ['key' => $setting['key'], 'value' => $text($setting['value'])];
            }
        }

        return $settings;
    }

    // String(value) of the screen: null became the text "null"
    private static function asTheScreenWrites(mixed $value): string
    {
        return $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }

    public function test_the_page_saves_again_after_a_first_save(): void
    {
        $headers = $this->bearer();

        // First save: the empty text fields go out empty, and Laravel turns them into NULL
        $this->putJson('/api/settings', ['settings' => $this->pageAsTheScreenSendsIt($headers, fn ($value) => $value === null ? '' : self::asTheScreenWrites($value))], $headers)->assertOk();
        $this->app['auth']->forgetGuards();

        // Reloaded and saved again as the screen does: it used to be refused ("Not a domain name: null")
        $this->putJson('/api/settings', ['settings' => $this->pageAsTheScreenSendsIt($headers, self::asTheScreenWrites(...))], $headers)->assertOk();
    }

    public function test_an_empty_setting_is_kept_empty_not_as_the_text_null(): void
    {
        $headers = $this->bearer();

        $this->putJson('/api/settings', ['settings' => [
            ['key' => 'sso_required_domains', 'value' => 'null'],
            ['key' => 'login_ip_allowlist', 'value' => ''],
        ]], $headers)->assertOk();

        $this->assertSame('', Setting::where('key', 'sso_required_domains')->value('value'));
        $this->assertSame('', Setting::where('key', 'login_ip_allowlist')->value('value'));
    }
}
