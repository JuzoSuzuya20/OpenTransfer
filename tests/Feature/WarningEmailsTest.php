<?php

namespace Tests\Feature;

use App\Jobs\sendDeletionWarningEmails;
use App\Jobs\sendEmail;
use App\Jobs\sendExpiredWarningEmails;
use App\Jobs\sendExpiryWarningEmails;
use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un partage sans propriétaire (dépôt inversé, compte supprimé) faisait
 * planter les tâches d'alerte avant tous les partages suivants.
 */
class WarningEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function share(?User $owner, $expiresAt, array $extra = []): Share
    {
        $share = Share::create([
            'user_id' => $owner?->id, 'name' => 'Partage', 'path' => 'x/' . Str::random(8),
            'long_id' => 'test-' . Str::lower(Str::random(12)), 'size' => 1, 'file_count' => 1,
            'expires_at' => $expiresAt, 'status' => 'ready',
        ]);
        $share->forceFill($extra)->save();

        return $share;
    }

    private function user(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'is_guest' => false]);
    }

    public function test_expired_warnings_skip_ownerless_shares_and_reach_the_others(): void
    {
        $orphan = $this->share(null, now()->subDay());
        $owned = $this->share($this->user(), now()->subDay());

        (new sendExpiredWarningEmails())->handle();

        $this->assertTrue((bool) $orphan->fresh()->sent_expired);
        $this->assertTrue((bool) $owned->fresh()->sent_expired);
        Queue::assertPushed(sendEmail::class, 1);
    }

    public function test_a_reverse_share_upload_warns_the_person_who_invited_it(): void
    {
        $inviter = $this->user();
        $invite = ReverseShareInvite::create(['user_id' => $inviter->id, 'recipient_name' => 'Fournisseur', 'recipient_email' => 'f@example.test', 'expires_at' => now()->addDay()]);
        $this->share(null, now()->addDay(), ['invite_id' => $invite->id]);

        (new sendExpiryWarningEmails())->handle();

        Queue::assertPushed(sendEmail::class, 1);
    }

    public function test_deletion_warnings_do_not_crash_on_ownerless_shares(): void
    {
        $orphan = $this->share(null, now()->subDays(60));

        (new sendDeletionWarningEmails())->handle();

        $this->assertTrue((bool) $orphan->fresh()->sent_deletion_warning);
        Queue::assertNothingPushed();
    }
}
