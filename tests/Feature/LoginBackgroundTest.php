<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// An admin may put an image behind the sign-in screen (Customisation). Only an image, of a
// bounded weight and size, never an SVG (it would carry script); the previous one is removed.
class LoginBackgroundTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backgrounds');
    }

    private function user(bool $admin): User
    {
        return User::factory()->create([
            'admin' => $admin,
            'active' => true,
            'must_change_password' => false,
            'is_guest' => false,
        ]);
    }

    private function stored(): string
    {
        return (string) Setting::where('key', 'login_background')->value('value');
    }

    private function pageSetting(string $key): mixed
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match("/data-settings='([^']*)'/", $html, $match));

        return json_decode(htmlspecialchars_decode($match[1]), true)[$key] ?? null;
    }

    public function test_an_admin_puts_an_image_behind_the_sign_in_screen(): void
    {
        $this->actingAs($this->user(true), 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('mairie.jpg', 1920, 1080)])
            ->assertOk();

        $file = $this->stored();
        $this->assertMatchesRegularExpression('/^login-[a-z0-9]{16}\.jpg$/', $file);
        Storage::disk('backgrounds')->assertExists($file);
        $this->assertSame($file, $this->pageSetting('login_background'));
    }

    public function test_a_member_cannot_change_the_sign_in_background(): void
    {
        $this->actingAs($this->user(false), 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('a.jpg')])
            ->assertForbidden();

        $this->assertSame('', $this->stored());
    }

    public function test_an_svg_is_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'fond.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($this->user(true), 'api')
            ->postJson('/api/settings/login-background', ['background' => $svg])
            ->assertUnprocessable();

        $this->assertSame('', $this->stored());
        $this->assertSame([], Storage::disk('backgrounds')->allFiles());
    }

    public function test_an_image_too_large_to_resize_safely_is_refused(): void
    {
        $this->actingAs($this->user(true), 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('huge.png', 9000, 9000)])
            ->assertUnprocessable();

        $this->assertSame('', $this->stored());
    }

    public function test_a_new_image_replaces_the_previous_one(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin, 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk();
        $first = $this->stored();

        $this->actingAs($admin, 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('b.png')])
            ->assertOk();

        Storage::disk('backgrounds')->assertMissing($first);
        Storage::disk('backgrounds')->assertExists($this->stored());
    }

    // The web server serves these files itself, with the type of their extension: a valid image
    // named page.html would open as a page of the application, scripts included
    public function test_a_background_image_keeps_the_extension_of_what_it_is(): void
    {
        // A real file, typed from its content as the server does (a fake one is typed from its name)
        $path = tempnam(sys_get_temp_dir(), 'bg');
        imagepng(imagecreatetruecolor(40, 40), $path);
        $png = new UploadedFile($path, 'page.html', null, null, true);

        $this->actingAs($this->user(true), 'api')
            ->postJson('/api/settings/backgrounds', ['background_image' => $png])
            ->assertOk()
            ->assertJsonPath('data.file', 'page.png');

        Storage::disk('backgrounds')->assertExists('page.png');
        Storage::disk('backgrounds')->assertMissing('page.html');
    }

    public function test_removing_the_image_brings_back_the_plain_screen(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin, 'api')
            ->postJson('/api/settings/login-background', ['background' => UploadedFile::fake()->image('a.webp')])
            ->assertOk();
        $file = $this->stored();

        $this->actingAs($admin, 'api')->deleteJson('/api/settings/login-background')->assertOk();

        $this->assertSame('', $this->stored());
        Storage::disk('backgrounds')->assertMissing($file);
    }
}
