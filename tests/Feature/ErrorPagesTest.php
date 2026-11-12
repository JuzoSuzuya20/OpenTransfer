<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// An address that leads nowhere shows a page of the application, in the language of the
// instance, with the way back home; not the bare page of the framework.
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_an_unknown_address_shows_the_page_of_the_application(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'fr']);

        $this->get('/une-adresse-qui-ne-mene-nulle-part')
            ->assertNotFound()
            ->assertSee('<html lang="fr">', false)
            ->assertSee('Page introuvable')
            ->assertSee("Retour à l'expéditeur")
            ->assertSee('href="/"', false);
    }

    public function test_the_page_speaks_the_default_language_of_the_instance(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'de']);

        $this->get('/nirgendwo')->assertNotFound()->assertSee('Seite nicht gefunden');
    }

    public function test_the_api_still_answers_in_json(): void
    {
        $this->getJson('/api/nothing-here')->assertNotFound()->assertJsonStructure(['message']);
    }
}
