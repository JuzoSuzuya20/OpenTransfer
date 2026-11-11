<?php

namespace App\Mail\Concerns;

use App\Services\SettingsService;
use App\Support\Locales;

/**
 * The words of the email layout, in each language: the line under the button and the footer.
 * The subjects and texts of each email live in its template, one per language.
 */
final class EmailTexts
{
    private const LAYOUT = [
        'en' => ['fallback' => 'If the button does not work, copy this address into your browser:', 'footer' => 'Sent by', 'date_format' => 'Y-m-d'],
        'fr' => ['fallback' => "Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :", 'footer' => 'Envoyé par', 'date_format' => 'd/m/Y'],
        'de' => ['fallback' => 'Falls die Schaltfläche nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:', 'footer' => 'Gesendet von', 'date_format' => 'd.m.Y'],
        'es' => ['fallback' => 'Si el botón no funciona, copie esta dirección en su navegador:', 'footer' => 'Enviado por', 'date_format' => 'd/m/Y'],
        'it' => ['fallback' => 'Se il pulsante non funziona, copia questo indirizzo nel browser:', 'footer' => 'Inviato da', 'date_format' => 'd/m/Y'],
        'nl' => ['fallback' => 'Werkt de knop niet? Kopieer dit adres naar je browser:', 'footer' => 'Verzonden door', 'date_format' => 'd-m-Y'],
        'pt' => ['fallback' => 'Se o botão não funcionar, copie este endereço para o navegador:', 'footer' => 'Enviado por', 'date_format' => 'd/m/Y'],
    ];

    // What the seeder ships: a different value was written by an admin, for the default language
    private const SHIPPED_FALLBACK = "If you're having trouble with the button above, copy and paste the URL below into your web browser.";

    public static function layout(string $locale): array
    {
        $texts = self::LAYOUT[$locale] ?? self::LAYOUT['en'];

        $custom = app(SettingsService::class)->get('email_template_fallback_text');
        if ($locale === Locales::default() && is_string($custom) && trim($custom) !== '' && $custom !== self::SHIPPED_FALLBACK) {
            $texts['fallback'] = $custom;
        }

        return $texts;
    }
}
