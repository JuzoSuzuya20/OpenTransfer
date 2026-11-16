<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The themes of Erugo gave way to the interface of OpenTransfer: the page no longer reads them, and
// their routes (one of which installed an uploaded file) answer no one any more. The table and its
// rows stay, as an instance moved from Erugo left them.
class ThemeRoutesGoneTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_the_routes_of_themes_answer_no_one(): void
    {
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        $this->getJson('/api/themes/active')->assertNotFound();
        foreach ([['get', '/api/themes'], ['post', '/api/themes'], ['delete', '/api/themes'], ['post', '/api/themes/set-active'], ['post', '/api/themes/install']] as [$method, $uri]) {
            $this->actingAs($admin, 'api')->json($method, $uri)->assertNotFound();
        }
    }

    public function test_the_download_of_a_share_answers_get_and_post_only(): void
    {
        foreach (['put', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/shares/does-not-exist/download')->assertStatus(405);
        }
    }
}
