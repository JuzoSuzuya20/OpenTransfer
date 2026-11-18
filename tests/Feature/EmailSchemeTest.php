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
 * The emails as the two mail readers of the organisation show them in their dark themes:
 *  - Thunderbird shows an email that says it is dark as written, and forces its own colours on
 *    one that does not (white text, blue links, light lines);
 *  - BlueMind takes the dark version of an email, then turns over the lightness of all of it: the
 *    light email of Uptime Kuma reads dark there, a dark one read light.
 * So the emails are dark and say so, with a light version for a web browser in a dark mode that is
 * not Firefox (whose engine Thunderbird has): BlueMind, in Edge or Chrome, turns it dark. Each fixed
 * colour belongs to a role, named by a class, which the light version gives its light colour.
 */
class EmailSchemeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** The colours of the dark email (resources/js/style.scss), and the role of the light version */
    private const ROLES = [
        'ot-b-page' => ['#161616', 'page'],
        'ot-b-card' => ['#1e1e1e', 'card'],
        'ot-b-field' => ['#242424', 'field'],
        'ot-c-head' => ['#ffffff', 'head'],
        'ot-c-text' => ['#cecece', 'text'],
        'ot-c-muted' => ['#929292', 'muted'],
        'ot-l-line' => ['#353535', 'line'],
        'ot-l-field' => ['#3a3a3a', 'line_field'],
        'ot-l-soft' => ['#2a2a2a', 'line_soft'],
    ];

    /** The light version of an indigo instance: the colours BlueMind turns into the dark design */
    private function palette(): array
    {
        return \App\Support\BlueMindDarkTheme::palette(\App\Support\Accents::email('indigo'));
    }

    private const LIGHT_VERSION = "@supports not ((-moz-appearance: none) or (-moz-orient: inline)) {\n  @media (prefers-color-scheme: dark) {";

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

    private function lightVersion(string $html): string
    {
        $start = strpos($html, self::LIGHT_VERSION);
        $this->assertNotFalse($start, 'a light version, for a web browser that is not Firefox, in a dark mode');

        return substr($html, $start, strpos($html, "\n  }\n  }", $start) - $start);
    }

    public function test_an_email_is_dark_and_says_so_for_thunderbird(): void
    {
        $html = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertStringContainsString('<meta name="color-scheme" content="dark">', $html);
        $this->assertStringContainsString('<meta name="supported-color-schemes" content="dark">', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*background:\s*#161616;/', $html);
        $this->assertMatchesRegularExpression('/<table[^>]*class="ot-b-card ot-l-line"[^>]*bgcolor="#1e1e1e"/', $html);
    }

    public function test_the_light_version_gives_each_role_the_colour_bluemind_turns_into_the_dark_design(): void
    {
        $light = $this->lightVersion($this->sent(new passwordResetMail('token', $this->user())));
        $palette = $this->palette();

        foreach (self::ROLES as $class => [, $role]) {
            $property = match ($class[3]) { 'b' => 'background-color', 'c' => 'color', 'l' => 'border-color' };
            $this->assertStringContainsString(".{$class} { {$property}: {$palette[$role]} !important; }", $light, $class);
        }
        $this->assertSame('#ffffff', $palette['page']);
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
                // The text of the button stays white on the accent, in both versions
                if (str_starts_with($tag, '<a ') && preg_match('/text-transform:\s*uppercase/', $tag)) {
                    continue;
                }
                $roles = [];
                foreach (self::ROLES as $class => [$dark]) {
                    $property = match ($class[3]) { 'b' => '(?:background|background-color|bgcolor)', 'c' => '(?<![-\w])color', 'l' => 'border(?:-top|-bottom)?' };
                    if (preg_match('/' . $property . '\s*[:=]\s*"?[^;"]*' . preg_quote($dark, '/') . '/i', $tag)) {
                        $roles[$class[3] . $dark][] = $class;
                    }
                }
                foreach ($roles as $classes) {
                    $this->assertMatchesRegularExpression('/class="[^"]*\b(' . implode('|', $classes) . ')\b/', $tag, class_basename($mail) . ': ' . $tag);
                }
            }
        }
    }

    public function test_the_accent_has_its_dark_and_its_light_tones(): void
    {
        $indigo = json_decode(file_get_contents(resource_path('js/lib/accents.json')), true)['indigo'];

        $html = $this->sent(new passwordResetMail('token', $this->user()));
        $light = $this->lightVersion($html);

        $this->assertMatchesRegularExpression('/<td[^>]*class="ot-b-fill"[^>]*bgcolor="' . $indigo['email']['fill'] . '"/', $html);
        $palette = $this->palette();
        $this->assertStringContainsString(".ot-b-fill { background-color: {$palette['fill']} !important; }", $light);
        $this->assertStringContainsString(".ot-c-accent { color: {$palette['accent']} !important; }", $light);
        $this->assertStringContainsString(".ot-b-mark { background-color: {$palette['mark']} !important; }", $light);
        // BlueMind turns the deep blue of the light theme of the application into pink: not that one
        $this->assertStringNotContainsString($indigo['light']['accent-fill'], $light);
    }

    public function test_the_text_of_the_button_stays_white_on_the_accent(): void
    {
        $html = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertMatchesRegularExpression('/<td[^>]*class="ot-b-fill"[^>]*>\s*<a [^>]*class="ot-c-on-fill"[^>]*color:\s*#ffffff;/', $html);
        $this->assertStringContainsString(".ot-c-on-fill { color: {$this->palette()['on_fill']} !important; }", $this->lightVersion($html));
    }

    // BlueMind makes a link of the name of the mail server: links in the text take the accent
    public function test_a_link_bluemind_makes_in_the_text_takes_the_accent(): void
    {
        $indigo = json_decode(file_get_contents(resource_path('js/lib/accents.json')), true)['indigo'];

        $html = $this->sent(new smtpTestMail($this->user(), 'smtp.example.test'));

        $this->assertStringContainsString(".ot-content a { color: {$indigo['email']['text']}; }", $html);
        $this->assertStringContainsString(".ot-content a { color: {$this->palette()['accent']} !important; }", $this->lightVersion($html));
    }

    public function test_a_template_an_admin_rewrote_before_goes_without_the_light_version(): void
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
        $this->assertStringNotContainsString('prefers-color-scheme: dark', $html);
    }

    public function test_a_template_an_admin_rewrote_from_the_classes_keeps_the_light_version(): void
    {
        $path = storage_path('templates/emails/fr/passwordResetMail.twig');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "{% extends 'emails/layout' %}\n\n{% block subject %}Mot de passe{% endblock %}\n\n{% block header %}Mot de passe{% endblock %}\n\n"
            . "{% block content %}<h2 class=\"ot-c-head\" style=\"color:#ffffff;\">Bonjour</h2>{% endblock %}\n");

        try {
            $html = $this->sent(new passwordResetMail('token', $this->user()));
        } finally {
            @unlink($path);
        }

        $this->lightVersion($html);
    }

    public function test_a_share_email_has_the_light_version_too(): void
    {
        $owner = User::factory()->create(['name' => 'Camille Martin', 'language' => 'fr']);
        $share = \App\Models\Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier marché travaux', 'path' => 'tests-' . \Illuminate\Support\Str::random(8),
            'long_id' => 'test-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)), 'size' => 11, 'file_count' => 1,
            'download_count' => 0, 'expires_at' => now()->addDays(3), 'status' => 'ready',
        ]);
        \App\Models\File::create(['name' => 'plans.pdf', 'original_name' => 'plans.pdf', 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);

        $html = $this->sent(new shareCreatedMail($owner, $share->fresh(), ['name' => 'Dominique', 'email' => 'dominique@example.test']));

        $this->lightVersion($html);
    }
}
