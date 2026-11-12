<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The link of a file request is valid as long as the request (seven days), opens once, and an
// unreadable link is a refusal, not a server error.
class GuestInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function inviteLinkToken(): string
    {
        Queue::fake();
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
        ], ['Authorization' => 'Bearer '.auth('api')->tokenById($member->id)])->assertOk();

        $token = null;
        Queue::assertPushed(sendEmail::class, function (sendEmail $job) use (&$token) {
            $token = $job->mailableData['token'];

            return true;
        });
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function accept(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/reverse-shares/accept?token='.urlencode($token));
    }

    public function test_the_link_opens_the_next_day(): void
    {
        $token = $this->inviteLinkToken();

        $this->travel(26)->hours();

        $this->accept($token)->assertOk()->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_the_link_opens_once(): void
    {
        $token = $this->inviteLinkToken();

        $this->accept($token)->assertOk();
        $this->assertGreaterThanOrEqual(400, $this->accept($token)->status());
    }

    public function test_the_link_no_longer_opens_after_the_request_expired(): void
    {
        $token = $this->inviteLinkToken();

        $this->travel(8)->days();

        $this->assertGreaterThanOrEqual(400, $this->accept($token)->status());
    }

    public function test_an_unreadable_link_is_refused_without_a_server_error(): void
    {
        $this->accept('not-a-link')->assertUnauthorized();
    }
}
