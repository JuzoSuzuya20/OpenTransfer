<?php

namespace Tests\Feature;

use App\Mail\emailVerificationMail;
use App\Mail\passwordResetMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The emails as webmails show them. Some (BlueMind) recompute the colours of an email one element
 * at a time to fit their own theme: they convert the blocks, but leave a piece of text that sets
 * its own colour as it was, white on what became light grey. And most block remote images until
 * the reader allows them: the logo travels with the email instead.
 */
class EmailRobustnessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

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

    /** @return \Symfony\Component\Mime\Email the email as it leaves */
    private function sent($mailable)
    {
        Mail::mailer('array')->to('camille@example.test')->send($mailable);

        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    public function test_no_piece_of_text_sets_its_own_colour(): void
    {
        $found = [];
        foreach (glob(resource_path('views/emails/{,*/}*.twig'), GLOB_BRACE) as $template) {
            if (preg_match_all('/<(strong|span|b|em|i|small|code)\b[^>]*style="[^"]*\bcolor\s*:/i', file_get_contents($template), $matches)) {
                $found[] = basename(dirname($template)) . '/' . basename($template) . ' (' . implode(', ', $matches[1]) . ')';
            }
        }

        $this->assertSame([], $found, 'Colours belong to the blocks (td, p, h2), which webmails convert together');
    }

    // BlueMind recolours the blocks (h2, p, td, a) but left a piece of a sentence white on the light
    // grey it turned the card into, as <strong> with a colour, <strong> without one and <span> in
    // bold alike: what a shipped template sets apart sits in a block of its own
    public function test_no_shipped_template_sets_apart_a_piece_of_a_sentence(): void
    {
        $found = [];
        foreach (glob(resource_path('views/emails/{,*/}*.twig'), GLOB_BRACE) as $template) {
            if (preg_match('/<(span|strong|b|em|i|small|code|font|mark)\b/i', file_get_contents($template))) {
                $found[] = basename(dirname($template)) . '/' . basename($template);
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_bold_of_a_rewritten_template_takes_the_colour_around_it(): void
    {
        $html = $this->sent(new passwordResetMail('token', $this->user()))->getHtmlBody();

        $this->assertMatchesRegularExpression('/\.ot-content strong, \.ot-content b \{ color: inherit !important; \}/', $html);
    }

    // BlueMind dresses a name of domain found in the text its own way, white on its light grey: it
    // stayed so in strong, span or td, coloured or not. A <wbr> after each dot, invisible and kept
    // out of a copy, leaves no name of domain in one piece of text
    // (the CSS inliner, whose parser knows no <wbr>, closes them: browsers ignore a </wbr>)
    public function test_the_name_of_the_server_sits_in_a_block_of_its_own_never_in_one_piece(): void
    {
        $html = $this->sent(new \App\Mail\smtpTestMail($this->user(), 'smtp.example.test'))->getHtmlBody();

        $this->assertMatchesRegularExpression('/<td[^>]*bgcolor="#242424"[^>]*color:#ffffff;[^>]*>\s*smtp\.<wbr>example\.<wbr>test(<\/wbr>)*\s*<\/td>/', $html);
        $this->assertStringNotContainsString('smtp.example.test', $html);
    }

    public function test_the_name_of_the_server_stays_escaped(): void
    {
        $html = $this->sent(new \App\Mail\smtpTestMail($this->user(), 'relay<b>.example.test'))->getHtmlBody();

        $this->assertStringContainsString('relay&lt;b&gt;.<wbr>example.<wbr>test', $html);
        $this->assertStringNotContainsString('relay<b>', $html);
    }

    public function test_the_code_to_type_sits_in_a_block_of_its_own(): void
    {
        $html = $this->sent(new emailVerificationMail('482915', $this->user()))->getHtmlBody();

        $this->assertMatchesRegularExpression('/<td[^>]*bgcolor="#242424"[^>]*>\s*482915\s*<\/td>/', $html);
    }

    public function test_the_logo_travels_with_the_email(): void
    {
        $email = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertMatchesRegularExpression('/<img src="cid:[^"]+"/', $email->getHtmlBody());
        $this->assertStringNotContainsString('/build/email/brand-mark', $email->getHtmlBody());
        $inline = array_values(array_filter($email->getAttachments(), fn ($part) => $part->getMediaSubtype() === 'png'));
        $this->assertCount(1, $inline);
        $this->assertSame(file_get_contents(resource_path('images/email/brand-mark.png')), $inline[0]->getBody());
    }

    public function test_the_logo_an_admin_uploaded_travels_too(): void
    {
        $logo = file_get_contents(resource_path('images/email/brand-mark-emerald.png'));
        Storage::disk('public')->put('images/logo.png', $logo);

        $email = $this->sent(new passwordResetMail('token', $this->user()));

        $inline = array_values(array_filter($email->getAttachments(), fn ($part) => $part->getMediaSubtype() === 'png'));
        $this->assertSame($logo, $inline[0]->getBody());
    }

    public function test_a_heavy_logo_stays_a_link(): void
    {
        // A logo of an admin can weigh much: every email would carry it
        Storage::disk('public')->put('images/logo.png', str_repeat('x', 300 * 1024));

        $email = $this->sent(new passwordResetMail('token', $this->user()));

        $this->assertStringContainsString('src="https://files.example.test/images/logo.png"', $email->getHtmlBody());
        $this->assertSame([], $email->getAttachments());
    }

    public function test_an_email_shown_without_being_sent_links_its_logo(): void
    {
        // The preview of a template: no message to carry the logo
        $html = (new passwordResetMail('token', $this->user()))->render();

        $this->assertStringContainsString('src="https://files.example.test/build/email/brand-mark.png"', $html);
    }
}
