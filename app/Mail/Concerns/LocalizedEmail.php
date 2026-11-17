<?php

namespace App\Mail\Concerns;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locales;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * An email in the language of its reader. Its template is looked up in this order:
 *  1. the version of that language an admin rewrote (storage/templates/emails/{locale}/);
 *  2. for the default language only, the template an admin rewrote before emails had languages
 *     (storage/templates/emails/), so that an existing instance keeps its texts;
 *  3. the version shipped for that language, then the English one.
 * The subject is the "subject" block of the template, or the subject an admin set before.
 */
trait LocalizedEmail
{
    public string $emailLocale = 'en';

    public array $layout = [];

    /** The head of the email: the mark of the application, or the logo an admin put in its place */
    public array $brand = [];

    abstract protected function templateName(): string;

    protected function speak(?string $locale): void
    {
        $this->emailLocale = Locales::isSupported($locale) ? $locale : 'en';
        $this->layout = EmailTexts::layout($this->emailLocale);
        // The logo is square, with the name the admin chose next to it, as in the application
        $this->brand = [
            'custom_logo' => !usesDefaultLogo(),
            // The main colour of the instance: its button, its label and its mark
            'accent' => \App\Support\Accents::email(\App\Support\Accents::chosen()),
            // Dark, with a light version for BlueMind; a template rewritten before goes without it
            'scheme' => $this->writtenBeforeTheLightVersion() ? 'dark' : 'dark-and-light',
        ];
    }

    /**
     * Before, the emails were dark only, and an admin who rewrote one copied its colours without the
     * classes of their roles: the light version would leave its light grey text on a white card.
     * Such a template goes without the light version.
     */
    private function writtenBeforeTheLightVersion(): bool
    {
        $path = match (true) {
            file_exists($this->rewrittenPath()) => $this->rewrittenPath(),
            $this->usesLegacyTemplate() => $this->legacyPath(),
            default => null,
        };
        if ($path === null) {
            return false;
        }
        $source = (string) file_get_contents($path);

        $colours = preg_match('/(?<![-\w])color\s*:\s*#(?:ffffff|fff|cecece|929292)\b/i', $source) === 1
            || preg_match('/#(?:1e1e1e|242424|2a2a2a|353535)\b/i', $source) === 1;

        return $colours && preg_match('/class="[^"]*\bot-[bcl]-/', $source) !== 1;
    }

    /**
     * The logo travels with the email: webmails block remote images until the reader allows them,
     * and the head of the email showed a broken image. Done at sending, after a queue, so that no
     * closure is ever serialised; an email only shown (a preview) has no message and links it.
     * The image is attached here and not from the template, which runs in the sandbox of Twig.
     */
    public function send($mailer)
    {
        if (!isset($this->brand['logo_src']) && ($logo = $this->logoToEmbed())) {
            $cid = bin2hex(random_bytes(8)) . '@logo.opentransfer';
            $this->brand['logo_src'] = "cid:{$cid}";
            $this->withSymfonyMessage(fn (Email $message) => $message->addPart(
                (new DataPart(new File($logo['path']), $logo['name'], $logo['type']))->asInline()->setContentId($cid)
            ));
        }

        return parent::send($mailer);
    }

    // Small enough to go in every email, and an image every webmail shows (an SVG stays a link)
    private const EMBEDDED_LOGO_MAX_BYTES = 200 * 1024;
    private const EMBEDDED_LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /** @return array{path: string, name: string, type: string}|null */
    private function logoToEmbed(): ?array
    {
        $path = ($this->brand['custom_logo'] ?? false)
            ? Storage::disk('public')->path('images/logo.png')
            : resource_path('images/email/' . ($this->brand['accent']['mark_file'] ?? 'brand-mark.png'));
        if (!is_file($path) || filesize($path) > self::EMBEDDED_LOGO_MAX_BYTES) {
            return null;
        }
        $type = @mime_content_type($path);
        if (!isset(self::EMBEDDED_LOGO_TYPES[$type])) {
            return null;
        }

        return ['path' => $path, 'name' => 'logo.' . self::EMBEDDED_LOGO_TYPES[$type], 'type' => $type];
    }

    private function rewrittenPath(): string
    {
        return storage_path("templates/emails/{$this->emailLocale}/{$this->templateName()}.twig");
    }

    private function legacyPath(): string
    {
        return storage_path("templates/emails/{$this->templateName()}.twig");
    }

    private function usesLegacyTemplate(): bool
    {
        return !file_exists($this->rewrittenPath()) && $this->emailLocale === Locales::default() && file_exists($this->legacyPath());
    }

    public function templateView(): string
    {
        $name = $this->templateName();
        if (file_exists($this->rewrittenPath())) {
            return "email-overrides::{$this->emailLocale}.$name";
        }
        if ($this->usesLegacyTemplate()) {
            return "emails.$name";
        }

        return View::exists("emails.{$this->emailLocale}.$name") ? "emails.{$this->emailLocale}.$name" : "emails.en.$name";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: $this->templateView());
    }

    private function subjectLine(): string
    {
        $name = $this->templateName();
        $former = Setting::where('key', "email_subject_{$name}.twig")->value('value');
        $formerWasRewritten = is_string($former) && trim($former) !== '' && $former !== EmailTexts::shippedSubject($name);

        if ($this->usesLegacyTemplate() || ($formerWasRewritten && $this->emailLocale === Locales::default() && !file_exists($this->rewrittenPath()))) {
            return (string) $former;
        }

        $template = app('twig')->load($this->templateView());
        $data = array_merge($this->buildViewData(), ['settings' => app(SettingsService::class)->getGlobalViewData()]);
        if (!$template->hasBlock('subject', $data)) {
            return (string) $former;
        }

        return trim(html_entity_decode(strip_tags($template->renderBlock('subject', $data)), ENT_QUOTES | ENT_HTML5));
    }
}
