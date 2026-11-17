<?php

namespace Tests\Feature;

use App\Mail\passwordResetMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The main colour of the instance (Appearance) is one of a few, tuned for both themes. The page
// arrives in it (no flash of the default colour), and the emails take it: their button and mark.
class AccentColorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function accents(): array
    {
        return json_decode(file_get_contents(base_path('resources/js/lib/accents.json')), true);
    }

    private function choose(string $accent): void
    {
        Setting::where('key', 'accent_color')->update(['value' => $accent]);
        app(SettingsService::class)->clearCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    public function test_the_default_colour_adds_no_style(): void
    {
        $this->get('/')->assertOk()->assertDontSee('id="ot-accent"', false);
    }

    public function test_the_page_arrives_in_the_chosen_colour(): void
    {
        $this->choose('emerald');
        $emerald = $this->accents()['emerald'];

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<style id="ot-accent">', $html);
        $this->assertStringContainsString('--ot-accent:'.$emerald['light']['accent'].';', $html);
        $this->assertStringContainsString(':root[data-theme="dark"]{', $html);
        $this->assertStringContainsString('--ot-accent:'.$emerald['dark']['accent'].';', $html);
    }

    public function test_the_page_arrives_with_the_logo_of_the_chosen_colour(): void
    {
        $this->choose('emerald');

        $this->assertStringContainsString('--ot-logo:url(/build/brand/logo-emerald.png);', $this->get('/')->assertOk()->getContent());
    }

    private function favicon(): string
    {
        $response = $this->get('/api/favicon')->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        return $response->baseResponse->getFile()->getPathname();
    }

    public function test_the_default_favicon_is_the_logo_of_the_chosen_colour(): void
    {
        Storage::fake('public');
        $this->assertSame(realpath(resource_path('images/brand/logo-indigo.png')), realpath($this->favicon()));

        $this->choose('orange');
        $this->assertSame(realpath(resource_path('images/brand/logo-orange.png')), realpath($this->favicon()));
    }

    public function test_a_favicon_an_admin_uploaded_stays(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('favicon.png', 'the favicon of the organisation');
        $this->choose('orange');

        $this->assertSame('the favicon of the organisation', file_get_contents($this->favicon()));
    }

    public function test_an_admin_chooses_one_of_the_colours(): void
    {
        $this->actingAs($this->admin(), 'api')->putJson('/api/settings', ['settings' => [['key' => 'accent_color', 'value' => 'raspberry']]])->assertOk();

        $this->assertSame('raspberry', Setting::where('key', 'accent_color')->value('value'));
    }

    public function test_a_colour_that_is_not_offered_is_refused(): void
    {
        $this->actingAs($this->admin(), 'api')->putJson('/api/settings', ['settings' => [['key' => 'accent_color', 'value' => 'red;}body{display:none']]])
            ->assertUnprocessable()->assertJsonPath('code', 'accent_invalid');

        $this->assertSame('indigo', Setting::where('key', 'accent_color')->value('value'));
    }

    public function test_the_emails_take_the_chosen_colour(): void
    {
        Storage::fake('public');
        Setting::where('key', 'application_url')->update(['value' => 'https://files.example.test']);
        $this->choose('orange');
        $orange = $this->accents()['orange'];

        $html = (new passwordResetMail('token', User::factory()->create()))->render();

        $this->assertStringContainsString('src="https://files.example.test/build/email/brand-mark-orange.png"', $html);
        // Dark, with the light tone in the light version (tests/Feature/EmailSchemeTest.php)
        $this->assertStringContainsString('background:'.$orange['email']['fill'], $html);
        $this->assertStringContainsString('.ot-b-fill { background-color: '.$orange['light']['accent-fill'].' !important; }', $html);
        $this->assertStringNotContainsString('#6262ee', $html);
    }
}
