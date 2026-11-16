<?php

namespace Tests\Feature;

use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A link of a file request opens once, for a while. When it no longer opens, the person who holds
// it learns why (already used, expired, gone) and whom to ask for another: a page of its own says
// so, not a sign-in form they have no account for.
class FileRequestLinkErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->member = User::factory()->create(['name' => 'Camille Martin', 'admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
    }

    private function ask(array $fields = []): string
    {
        $this->fresh();
        $link = $this->postJson('/api/reverse-shares/invite', array_merge(['delivery' => 'link', 'recipient_name' => 'Dossier Martin', 'expires_in_days' => 3], $fields), [
            'Authorization' => 'Bearer '.auth('api')->tokenById($this->member->id),
        ])->assertOk()->json('data.link');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $query['invite_token'];
    }

    private function open(string $token, ?string $password = null)
    {
        $this->fresh();

        return $this->postJson('/api/reverse-shares/accept', array_filter(['token' => $token, 'password' => $password]));
    }

    public function test_a_link_already_used_says_so_and_who_to_ask(): void
    {
        $token = $this->ask();
        $this->open($token)->assertOk();

        $this->open($token)->assertStatus(410)
            ->assertJsonPath('code', 'request_used')
            ->assertJsonPath('data.request.from', 'Camille Martin')
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_an_expired_link_says_so_and_until_when_it_was_valid(): void
    {
        $token = $this->ask();
        $this->travel(4)->days();

        $this->open($token)->assertStatus(410)
            ->assertJsonPath('code', 'request_expired')
            ->assertJsonPath('data.request.from', 'Camille Martin')
            ->assertJsonStructure(['data' => ['request' => ['expires_at']]]);
    }

    public function test_a_cancelled_link_is_one_that_no_longer_exists(): void
    {
        $token = $this->ask();
        $invite = ReverseShareInvite::sole();
        $this->fresh();
        $this->deleteJson("/api/reverse-shares/invites/{$invite->id}", [], ['Authorization' => 'Bearer '.auth('api')->tokenById($this->member->id)])->assertOk();

        $this->open($token)->assertNotFound()->assertJsonPath('code', 'request_unknown');
    }

    public function test_a_link_cut_short_is_one_that_no_longer_exists(): void
    {
        $this->open('not-a-link')->assertUnauthorized()->assertJsonPath('code', 'request_unknown');
    }

    public function test_a_protected_request_asks_for_its_password_with_a_code(): void
    {
        $token = $this->ask(['password' => 'lot-3']);

        $this->open($token)->assertUnauthorized()->assertJsonPath('code', 'request_password_required');
        $this->open($token, 'wrong')->assertUnauthorized()->assertJsonPath('code', 'request_password_invalid');
    }
}
