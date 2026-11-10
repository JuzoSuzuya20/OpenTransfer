<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Each screen of the application has its own address: opening one directly, from a bookmark or
// after a reload, serves the application, which then shows that screen.
class AppPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public static function pages(): array
    {
        return [
            ['/my-shares'], ['/requests'], ['/stats'], ['/profile'], ['/about'],
            ['/admin'], ['/admin/system'], ['/admin/branding'], ['/admin/email-templates'],
            ['/admin/users'], ['/admin/all-shares'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_page_serves_the_application(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_an_unknown_administration_section_is_not_found(): void
    {
        $this->get('/admin/nothing')->assertNotFound();
    }

    public function test_the_sign_in_callback_of_the_identity_providers_is_not_a_page(): void
    {
        $this->get('/auth/provider/00000000-0000-0000-0000-000000000000/callback')
            ->assertDontSee('<div id="app"></div>', false);
    }
}
