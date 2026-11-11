<?php

namespace App\Mail\Concerns;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locales;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\View;

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

    abstract protected function templateName(): string;

    protected function speak(?string $locale): void
    {
        $this->emailLocale = Locales::isSupported($locale) ? $locale : 'en';
        $this->layout = EmailTexts::layout($this->emailLocale);
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
