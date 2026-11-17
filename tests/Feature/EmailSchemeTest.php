<?php

namespace Tests\Feature;

use App\Mail\emailVerificationMail;
use App\Mail\passwordResetMail;
use App\Mail\shareCreatedMail;
use App\Mail\smtpTestMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The emails are light, with a dark version for the mail readers in a dark mode. BlueMind in its
 * dark theme turns over the lightness of every email: a light one (Uptime Kuma) reads dark there,
 * the dark ones OpenTransfer sent read light. Thunderbird shows an email as written, and takes the
 * dark version from prefers-color-scheme. Each fixed colour of an email belongs to a role, named by
 * a class, which the dark version gives its colour of the dark theme of the application.
 */
class EmailSchemeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** The colours of the light version, and of the dark one, as in resources/js/style.scss */
    private const ROLES = [
        'ot-b-page' => ['#f6f6f6', '#161616'],
        'ot-b-card' => ['#ffffff', '#1e1e1e'],
        'ot-b-field' => ['#f6f6f6', '#242424'],
        'ot-c-head' => ['#161616', '#ffffff'],
        'ot-c-text' => ['#3a3a3a', '#cecece'],
        'ot-c-muted' => ['#666666', '#929292'],
        'ot-l-line' => ['#dddddd', '#353535'],
        'ot-l-field' => ['#cecece', '#3a3a3a'],
        'ot-l-soft' => ['#e5e5e5', '#2a2a2a'],
    ];

    /** The colours only the dark design had (#161616, #3a3a3a, #cecece are light ones too) */
    private const DARK_ONLY = ['#1e1e1e', '#242424', '#2a2a2a', '#353535', '#929292'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Setting::where('key', 'application_url')->update(['value' => 'https://files.example.test']);
        app(SettingsService::class)->clearCache();
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille@example.test', 'language' => 'fr']);
    }

    private function sent($mailable): string
    {
        Mail::mailer('array')->to('camille@example.test')->send($mailable);

        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
    }

    private function darkBlock(string $html): string
    {
        $this->assertSame(1, preg_match('/@media \(prefers-color-scheme: dark\) \{(.*?)\n  \}/s', $html, $match), 'a dark version');

        return $match[1];
    }

    public function test_an_email_is_light_and_says_it_has_a_dark_version(): void
    {
        $html = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('<meta name="supported-color-schemes" content="light dark">', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*background:\s*#f6f6f6;/', $html);
        $this->assertMatchesRegularExpression('/<table[^>]*class="ot-b-card ot-l-line"[^>]*bgcolor="#ffffff"/', $html);
    }

    public function test_the_dark_version_gives_each_role_its_colour_of_the_dark_theme(): void
    {
        $dark = $this->darkBlock($this->sent(new passwordResetMail('token', $this->user())));

        foreach (self::ROLES as $class => [, $darkColour]) {
            $property = match ($class[3]) { 'b' => 'background-color', 'c' => 'color', 'l' => 'border-color' };
            $this->assertStringContainsString(".{$class} { {$property}: {$darkColour} !important; }", $dark, $class);
        }
    }

    public function test_no_shipped_template_keeps_a_colour_of_the_dark_design(): void
    {
        $found = [];
        foreach (glob(resource_path('views/emails/*/*.twig')) as $template) {
            $source = strtolower(file_get_contents($template));
            foreach (self::DARK_ONLY as $colour) {
                if (str_contains($source, $colour)) {
                    $found[] = basename(dirname($template)) . '/' . basename($template) . " {$colour}";
                }
            }
            // White or light grey text was the dark design: the light one writes it dark
            if (preg_match('/(?<![-\w])color\s*:\s*#(ffffff|cecece)\b/', $source, $match)) {
                $found[] = basename(dirname($template)) . '/' . basename($template) . " color #{$match[1]}";
            }
        }

        $this->assertSame([], $found);
    }

    public function test_every_fixed_colour_of_an_email_has_its_role(): void
    {
        $user = $this->user();
        $mails = [new passwordResetMail('token', $user), new emailVerificationMail('482915', $user), new smtpTestMail($user, 'smtp.example.test')];
        foreach ($mails as $mail) {
            $html = $this->sent($mail);
            // Only the body: the head holds the styles
            $body = substr($html, strpos($html, '<body'));
            preg_match_all('/<(\w+)\b[^>]*>/', $body, $tags);
            foreach ($tags[0] as $tag) {
                $roles = [];
                foreach (self::ROLES as $class => [$light]) {
                    $property = match ($class[3]) { 'b' => '(?:background|background-color|bgcolor)', 'c' => '(?<![-\w])color', 'l' => 'border(?:-top|-bottom)?' };
                    if (preg_match('/' . $property . '\s*[:=]\s*"?[^;"]*' . preg_quote($light, '/') . '/i', $tag)) {
                        $roles[$class[3] . $light][] = $class;
                    }
                }
                foreach ($roles as $classes) {
                    $this->assertMatchesRegularExpression('/class="[^"]*\b(' . implode('|', $classes) . ')\b/', $tag, class_basename($mail) . ': ' . $tag);
                }
            }
        }
    }

    public function test_the_accent_has_its_light_and_its_dark_tones(): void
    {
        $indigo = json_decode(file_get_contents(resource_path('js/lib/accents.json')), true)['indigo'];

        $html = $this->sent(new passwordResetMail('token', $this->user()));
        $dark = $this->darkBlock($html);

        $this->assertMatchesRegularExpression('/<td[^>]*class="ot-b-fill"[^>]*bgcolor="' . $indigo['light']['accent-fill'] . '"/', $html);
        $this->assertStringContainsString(".ot-b-fill { background-color: {$indigo['email']['fill']} !important; }", $dark);
        $this->assertStringContainsString(".ot-c-accent { color: {$indigo['email']['text']} !important; }", $dark);
        $this->assertStringContainsString(".ot-b-mark { background-color: {$indigo['email']['mark']} !important; }", $dark);
    }

    public function test_the_text_of_the_button_stays_white_on_the_accent(): void
    {
        $html = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertMatchesRegularExpression('/<td[^>]*class="ot-b-fill"[^>]*>\s*<a [^>]*color:\s*#ffffff;/', $html);
    }

    public function test_a_template_an_admin_rewrote_for_the_dark_design_keeps_it(): void
    {
        $path = storage_path('templates/emails/fr/passwordResetMail.twig');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "{% extends 'emails/layout' %}\n\n{% block subject %}Mot de passe{% endblock %}\n\n{% block header %}Mot de passe{% endblock %}\n\n"
            . "{% block content %}<h2 style=\"color:#ffffff;\">Bonjour</h2><p style=\"color:#929292;\">Texte gris clair</p>{% endblock %}\n");

        try {
            $html = $this->sent(new passwordResetMail('token', $this->user()));
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('<meta name="color-scheme" content="dark">', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*background:\s*#161616;/', $html);
        $this->assertStringNotContainsString('prefers-color-scheme: dark', $html);
    }

    public function test_a_light_share_email_reads_the_same_way(): void
    {
        $owner = User::factory()->create(['name' => 'Camille Martin', 'language' => 'fr']);
        $share = \App\Models\Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier marché travaux', 'path' => 'tests-' . \Illuminate\Support\Str::random(8),
            'long_id' => 'test-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)), 'size' => 11, 'file_count' => 1,
            'download_count' => 0, 'expires_at' => now()->addDays(3), 'status' => 'ready',
        ]);
        \App\Models\File::create(['name' => 'plans.pdf', 'original_name' => 'plans.pdf', 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);

        $html = $this->sent(new shareCreatedMail($owner, $share->fresh(), ['name' => 'Dominique', 'email' => 'dominique@example.test']));

        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->darkBlock($html);
    }
}
