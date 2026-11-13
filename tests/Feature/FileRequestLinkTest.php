<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A file request can also be a link the member passes on themselves (a chat, a phone call),
// without the address of the person who drops the files: nothing is e-mailed, the link comes
// back to the member. It opens once, for as long as the member chose, like an e-mailed one.
class FileRequestLinkTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function askByLink(array $fields = [])
    {
        return $this->postJson('/api/reverse-shares/invite', array_merge([
            'delivery' => 'link',
            'recipient_name' => 'Dossier Martin',
            'message' => 'Les pièces signées',
        ], $fields), $this->bearer($this->member()));
    }

    private function open(string $link)
    {
        $this->app['auth']->forgetGuards();
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $this->getJson('/api/reverse-shares/accept?token='.urlencode($query['invite_token']));
    }

    public function test_a_request_by_link_sends_nothing_and_gives_the_link_back(): void
    {
        $response = $this->askByLink()->assertOk();

        $link = $response->json('data.link');
        $this->assertStringStartsWith(rtrim((string) \App\Models\Setting::where('key', 'application_url')->value('value'), '/').'/?invite_token=', $link);
        Queue::assertNotPushed(sendEmail::class);

        $invite = ReverseShareInvite::first();
        $this->assertSame('Dossier Martin', $invite->recipient_name);
        $this->assertSame('', $invite->recipient_email);
        $this->assertNotNull($invite->guest_user_id);
    }

    public function test_the_link_opens_once(): void
    {
        $link = $this->askByLink()->json('data.link');

        $this->open($link)->assertOk()->assertJsonStructure(['data' => ['access_token']]);
        $this->assertGreaterThanOrEqual(400, $this->open($link)->status());
    }

    public function test_the_link_lasts_as_long_as_the_member_chose(): void
    {
        $link = $this->askByLink(['expires_in_days' => 14])->json('data.link');
        $this->assertTrue(ReverseShareInvite::first()->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));

        $this->travel(13)->days();
        $this->open($link)->assertOk();
    }

    public function test_a_link_for_one_day_no_longer_opens_the_day_after(): void
    {
        $link = $this->askByLink(['expires_in_days' => 1])->json('data.link');

        $this->travel(26)->hours();
        $this->assertGreaterThanOrEqual(400, $this->open($link)->status());
    }

    public function test_a_validity_out_of_the_choices_is_refused(): void
    {
        foreach ([0, 31, 'abc', 2] as $days) {
            $this->askByLink(['expires_in_days' => $days])->assertUnprocessable();
        }
        $this->assertSame(0, ReverseShareInvite::count());
    }

    public function test_an_e_mailed_request_still_needs_an_address_and_follows_the_validity(): void
    {
        $member = $this->member();
        $this->postJson('/api/reverse-shares/invite', ['recipient_name' => 'Marie Durand'], $this->bearer($member))->assertUnprocessable();

        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'expires_in_days' => 3,
        ], $this->bearer($member))->assertOk()->assertJsonPath('data.link', null);

        Queue::assertPushed(sendEmail::class);
        $this->assertTrue(ReverseShareInvite::first()->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }

    public function test_the_list_says_which_requests_went_by_link(): void
    {
        $member = $this->member();
        $this->postJson('/api/reverse-shares/invite', ['delivery' => 'link', 'recipient_name' => 'Dossier Martin'], $this->bearer($member))->assertOk();

        $this->getJson('/api/reverse-shares/invites', $this->bearer($member))
            ->assertOk()
            ->assertJsonPath('data.invites.0.delivery', 'link');
    }
}
