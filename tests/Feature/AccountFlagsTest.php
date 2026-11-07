<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * admin, active, is_guest et must_change_password ne sont plus assignables
 * en masse : chaque chemin de création doit les fixer lui-même, sans quoi
 * un compte auto-inscrit naîtrait actif (défaut de la colonne) et un invité
 * deviendrait un compte complet.
 */
class AccountFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    public function test_privileged_flags_cannot_be_mass_assigned(): void
    {
        $user = new User();
        $user->fill(['name' => 'Eve', 'email' => 'eve@example.test', 'admin' => true, 'active' => true, 'is_guest' => false, 'must_change_password' => false]);

        foreach (['admin', 'active', 'is_guest', 'must_change_password'] as $flag) {
            $this->assertArrayNotHasKey($flag, $user->getAttributes(), "{$flag} must not be fillable");
        }
    }

    public function test_a_self_registered_account_is_created_inactive(): void
    {
        Setting::where('key', 'self_registration_enabled')->update(['value' => 'true']);
        Setting::where('key', 'self_registration_allow_any_domain')->update(['value' => 'true']);

        $this->postJson('/api/auth/register', [
            'name' => 'New Colleague', 'email' => 'new@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSuccessful();

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertFalse((bool) $user->active);
        $this->assertFalse((bool) $user->admin);
        $this->postJson('/api/auth/login', ['email' => 'new@example.test', 'password' => 'password123'])->assertForbidden();
    }

    public function test_a_reverse_share_invite_creates_a_guest_account(): void
    {
        Setting::where('key', 'allow_reverse_shares')->update(['value' => 'true']);

        $this->actingAs($this->user(), 'api')->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Supplier', 'recipient_email' => 'supplier@example.test',
        ])->assertSuccessful();

        $guest = User::where('name', 'Supplier')->firstOrFail();
        $this->assertTrue((bool) $guest->is_guest);
        $this->assertFalse((bool) $guest->admin);
    }

    public function test_an_admin_can_still_create_and_promote_users(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->actingAs($admin, 'api')->postJson('/api/users', ['name' => 'Bob', 'email' => 'bob@example.test', 'admin' => true])->assertSuccessful();
        $bob = User::where('email', 'bob@example.test')->firstOrFail();
        $this->assertTrue((bool) $bob->admin);
        $this->assertTrue((bool) $bob->active);

        $this->actingAs($admin, 'api')->putJson("/api/users/{$bob->id}", ['admin' => false, 'must_change_password' => true])->assertSuccessful();
        $this->assertFalse((bool) $bob->fresh()->admin);
        $this->assertTrue((bool) $bob->fresh()->must_change_password);
    }

    public function test_the_first_account_created_at_setup_is_an_admin(): void
    {
        $this->postJson('/api/setup', [
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSuccessful();

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue((bool) $owner->admin);
        $this->assertTrue((bool) $owner->active);
    }

    public function test_a_user_cannot_promote_themselves_through_their_profile(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/users/me', ['name' => 'Still Me', 'admin' => true, 'active' => true]);

        $this->assertFalse((bool) $user->fresh()->admin);
    }
}
