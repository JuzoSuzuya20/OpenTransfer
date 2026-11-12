<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The recipients of a share each get an e-mail from the organisation: they are real addresses,
// and one send reaches a bounded number of them. Checked before anything is created.
class ShareRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function create(array $recipients)
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        return $this->postJson('/api/uploads/create-share-from-uploads', [
            'upload_id' => 'upload-1',
            'name' => 'Rapport',
            'uploadIds' => ['does-not-exist'],
            'expiry_date' => now()->addDays(3)->toDateString(),
            'recipients' => $recipients,
        ], ['Authorization' => 'Bearer '.auth('api')->tokenById($member->id)]);
    }

    public function test_an_address_that_is_not_one_is_refused(): void
    {
        $this->create([['name' => 'Marie', 'email' => 'pas-une-adresse']])->assertUnprocessable();

        $this->assertSame(0, Share::count());
        Queue::assertNotPushed(sendEmail::class);
    }

    public function test_one_send_reaches_fifty_recipients_at_most(): void
    {
        $recipients = array_map(fn ($i) => ['name' => "p{$i}@example.test", 'email' => "p{$i}@example.test"], range(1, 51));

        $this->create($recipients)->assertUnprocessable();

        Queue::assertNotPushed(sendEmail::class);
    }

    public function test_recipients_as_the_app_sends_them_pass_the_check(): void
    {
        // Past the recipients check, the send stops on the missing upload, not on them
        $response = $this->create([['id' => 'a1', 'name' => 'marie@example.test', 'email' => 'marie@example.test']]);

        $this->assertNotContains('recipients.0.email', array_keys($response->json('data.errors') ?? []));
    }
}
