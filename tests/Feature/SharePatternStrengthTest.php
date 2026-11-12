<?php

namespace Tests\Feature;

use App\Http\Controllers\SharesController;
use App\Models\Setting;
use App\Models\User;
use App\Services\PatternGenerator;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// A share without a password is read by whoever has its link: in the "pattern" mode the admin
// chooses its shape, and a short one (six characters, 36 bits; "###", a thousand links) could be
// enumerated. A pattern now needs 45 bits of chance, and looking for shares that do not exist
// is limited like guessing a password.
class SharePatternStrengthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function setting(string $key, string $value): void
    {
        Setting::where('key', $key)->update(['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    private function save(array $settings)
    {
        $payload = array_map(fn ($key, $value) => ['key' => $key, 'value' => $value], array_keys($settings), $settings);

        return $this->putJson('/api/settings', ['settings' => $payload], $this->bearer($this->admin()));
    }

    public function test_the_strength_of_a_pattern_is_the_chance_it_carries(): void
    {
        $generator = new PatternGenerator();

        $this->assertEqualsWithDelta(6 * log(62, 2), $generator->bits('******'), 0.01);
        $this->assertEqualsWithDelta(3 * log(10, 2), $generator->bits('###'), 0.01);
        $this->assertEqualsWithDelta(32 * 4, $generator->bits('XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'), 0.01);
        // Literal characters and escapes carry none, a class carries the choice it offers
        $this->assertEqualsWithDelta(0, $generator->bits('doc-\#\*'), 0.01);
        $this->assertEqualsWithDelta(log(3, 2) + log(10, 2), $generator->bits('[ABC][0-9]'), 0.01);
    }

    public function test_a_pattern_too_short_is_refused_when_links_follow_it(): void
    {
        $this->save(['share_url_mode' => 'pattern', 'share_url_pattern' => '******'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'share_url_pattern_weak')
            ->assertJsonPath('data.minimum_bits', PatternGenerator::MIN_BITS);

        $this->assertSame('haiku', Setting::where('key', 'share_url_mode')->value('value'));
    }

    public function test_switching_to_the_pattern_mode_checks_the_pattern_already_saved(): void
    {
        $this->setting('share_url_pattern', '####');

        $this->save(['share_url_mode' => 'pattern'])->assertUnprocessable()->assertJsonPath('code', 'share_url_pattern_weak');
    }

    public function test_a_long_enough_pattern_is_saved(): void
    {
        $this->save(['share_url_mode' => 'pattern', 'share_url_pattern' => 'doc-********'])->assertOk();

        $this->assertSame('doc-********', Setting::where('key', 'share_url_pattern')->value('value'));
    }

    public function test_the_pattern_does_not_matter_while_links_are_haikus(): void
    {
        $this->save(['share_url_mode' => 'haiku', 'share_url_pattern' => '###'])->assertOk();
    }

    public function test_a_short_pattern_saved_before_still_gives_links_that_cannot_be_guessed(): void
    {
        $this->setting('share_url_mode', 'pattern');
        $this->setting('share_url_pattern', '###');

        $id = (new SharesController())->generateLongId();

        $this->assertGreaterThanOrEqual(PatternGenerator::MIN_BITS, strlen($id) * log(62, 2));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]+$/', $id);
    }

    public function test_the_shipped_presets_and_default_are_strong_enough(): void
    {
        $generator = new PatternGenerator();
        $default = Setting::where('key', 'share_url_pattern')->value('value');

        $this->assertGreaterThanOrEqual(PatternGenerator::MIN_BITS, $generator->bits($default));

        $presets = file_get_contents(resource_path('js/components/settings/system.vue'));
        preg_match_all("/pattern: '([^']+)'/", $presets, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $pattern) {
            $this->assertGreaterThanOrEqual(PatternGenerator::MIN_BITS, $generator->bits($pattern), $pattern);
        }
    }

    public function test_looking_for_shares_that_do_not_exist_is_limited(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson("/api/shares/no-such-share-{$attempt}")->assertNotFound();
        }

        $this->getJson('/api/shares/no-such-share-again')->assertStatus(429);
        $this->get('/api/shares/no-such-share-again/download')->assertStatus(429);
    }
}
