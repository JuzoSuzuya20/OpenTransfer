<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Optional self-hosted proof of work on the forms open to anyone, in the ALTCHA
 * format, adapted from the moutonnoireu/erugo-security fork. Nothing leaves the
 * server, and SSO is not concerned.
 */
class AltchaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function enable(int $difficulty = 1000): void
    {
        Setting::where('key', 'altcha_enabled')->update(['value' => 'true']);
        Setting::where('key', 'altcha_max_number')->update(['value' => (string) $difficulty]);
        app(SettingsService::class)->clearCache();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function challenge(): array
    {
        return $this->getJson('/api/auth/challenge')->assertOk()->json('data');
    }

    /** What the browser does: tries numbers until the hash matches. */
    private function number(array $challenge): int
    {
        for ($number = 0; $number <= $challenge['maxnumber']; $number++) {
            if (hash('sha256', $challenge['salt'].$number) === $challenge['challenge']) {
                return $number;
            }
        }

        $this->fail('No solution');
    }

    private function encode(array $challenge, $number, array $overrides = []): string
    {
        return base64_encode(json_encode(array_merge([
            'algorithm' => $challenge['algorithm'],
            'challenge' => $challenge['challenge'],
            'number' => $number,
            'salt' => $challenge['salt'],
            'signature' => $challenge['signature'],
        ], $overrides)));
    }

    private function solution(): string
    {
        $challenge = $this->challenge();

        return $this->encode($challenge, $this->number($challenge));
    }

    private function login(User $user, ?string $altcha)
    {
        return $this->postJson('/api/auth/login', array_filter([
            'email' => $user->email, 'password' => 'password', 'altcha' => $altcha,
        ]));
    }

    // --- Off by default ---

    public function test_nothing_changes_by_default(): void
    {
        $this->assertSame('false', Setting::where('key', 'altcha_enabled')->value('value'));

        $this->login($this->user(), null)->assertOk();
    }

    public function test_no_challenge_is_given_while_it_is_off(): void
    {
        $this->getJson('/api/auth/challenge')->assertNotFound();
    }

    public function test_seeding_on_every_start_keeps_it_on(): void
    {
        $this->enable();

        $this->seed(SettingsSeeder::class);

        $this->assertSame('true', Setting::where('key', 'altcha_enabled')->value('value'));
    }

    // --- The forms open to anyone ---

    public function test_signing_in_needs_a_solution(): void
    {
        $user = $this->user();
        $this->enable();

        $this->login($user, null)
            ->assertStatus(422)
            ->assertJson(['code' => 'altcha_invalid'])
            ->assertJsonMissingPath('data.access_token');

        $this->login($user, $this->solution())->assertOk();
    }

    #[DataProvider('protectedForms')]
    public function test_a_form_open_to_anyone_needs_a_solution(string $uri): void
    {
        $this->enable();

        $this->postJson($uri, ['email' => 'someone@example.test'])
            ->assertStatus(422)
            ->assertJson(['code' => 'altcha_invalid']);
    }

    public static function protectedForms(): array
    {
        return [
            'forgot password' => ['/api/auth/forgot-password'],
            'register' => ['/api/auth/register'],
            'resend verification' => ['/api/auth/resend-verification'],
        ];
    }

    public function test_a_solution_does_not_replace_the_password(): void
    {
        $user = $this->user();
        $this->enable();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong', 'altcha' => $this->solution()])
            ->assertUnauthorized();
    }

    // --- What counts as a solution ---

    public function test_a_solution_works_only_once(): void
    {
        $user = $this->user();
        $this->enable();
        $solution = $this->solution();

        $this->login($user, $solution)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->login($user, $solution)->assertStatus(422);
    }

    public function test_a_wrong_number_is_refused(): void
    {
        $this->enable();
        $challenge = $this->challenge();

        $this->login($this->user(), $this->encode($challenge, $this->number($challenge) + 1))->assertStatus(422);
    }

    /**
     * Otherwise a bot would write itself an easy challenge instead of doing the work.
     */
    public function test_a_challenge_not_issued_here_is_refused(): void
    {
        $this->enable();
        $salt = 'mine?expires='.(time() + 600);
        $forged = ['algorithm' => 'SHA-256', 'salt' => $salt, 'challenge' => hash('sha256', $salt.'1'), 'signature' => str_repeat('0', 64)];

        $this->login($this->user(), $this->encode($forged, 1))->assertStatus(422);
    }

    public function test_an_old_challenge_is_refused(): void
    {
        $this->enable();
        $solution = $this->solution();

        $this->travel(11)->minutes();

        $this->login($this->user(), $solution)->assertStatus(422);
    }

    public function test_the_expiry_cannot_be_pushed_back(): void
    {
        $this->enable();
        $challenge = $this->challenge();
        $number = $this->number($challenge);
        $later = preg_replace('/expires=\d+/', 'expires='.(time() + 86400), $challenge['salt']);

        $this->login($this->user(), $this->encode($challenge, $number, ['salt' => $later]))->assertStatus(422);
    }

    #[DataProvider('malformedSolutions')]
    public function test_malformed_solutions_are_refused(callable $build): void
    {
        $this->enable();
        $challenge = $this->challenge();

        $this->postJson('/api/auth/login', ['email' => 'a@example.test', 'password' => 'x', 'altcha' => $build($challenge, $this->number($challenge))])
            ->assertStatus(422)
            ->assertJson(['code' => 'altcha_invalid']);
    }

    public static function malformedSolutions(): array
    {
        return [
            'not base64' => [fn () => '%%%'],
            'not json' => [fn () => base64_encode('not json')],
            'a json scalar' => [fn () => base64_encode('12')],
            'fields missing' => [fn () => base64_encode('{"number":1}')],
            'number as text' => [fn ($c, $n) => base64_encode(json_encode(['algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => (string) $n, 'salt' => $c['salt'], 'signature' => $c['signature']]))],
            'negative number' => [fn ($c) => base64_encode(json_encode(['algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => -1, 'salt' => $c['salt'], 'signature' => $c['signature']]))],
            'another algorithm' => [fn ($c, $n) => base64_encode(json_encode(['algorithm' => 'MD5', 'challenge' => $c['challenge'], 'number' => $n, 'salt' => $c['salt'], 'signature' => $c['signature']]))],
            'no expiry in the salt' => [fn ($c, $n) => base64_encode(json_encode(['algorithm' => $c['algorithm'], 'challenge' => hash('sha256', 'salt'.$n), 'number' => $n, 'salt' => 'salt', 'signature' => $c['signature']]))],
            'far too long' => [fn () => str_repeat('A', 5000)],
            'an array' => [fn () => ['not', 'a', 'string']],
        ];
    }

    // --- The challenge ---

    public function test_a_challenge_can_be_solved_and_never_gives_the_answer(): void
    {
        $this->enable(5000);
        $challenge = $this->challenge();

        $this->assertSame('SHA-256', $challenge['algorithm']);
        $this->assertSame(5000, $challenge['maxnumber']);
        $this->assertArrayNotHasKey('number', $challenge);
        $this->assertLessThanOrEqual(5000, $this->number($challenge));
    }

    public function test_two_challenges_differ(): void
    {
        $this->enable();

        $this->assertNotSame($this->challenge()['challenge'], $this->challenge()['challenge']);
    }

    public function test_asking_for_challenges_is_limited(): void
    {
        $this->enable();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/auth/challenge')->assertOk();
        }

        $this->getJson('/api/auth/challenge')->assertStatus(429);
    }

    // --- Administration ---

    public function test_the_login_screen_is_told_when_it_is_on_but_not_the_rest(): void
    {
        $this->enable();

        $this->get('/')->assertOk()->assertSee('altcha_enabled', false)->assertDontSee('altcha_max_number', false);
    }

    public function test_only_a_reasonable_difficulty_can_be_saved(): void
    {
        $admin = $this->user(['admin' => true]);

        foreach (['10', '999999999', 'hard', ''] as $value) {
            $this->putJson('/api/settings', ['settings' => [['key' => 'altcha_max_number', 'value' => $value]]], $this->bearer($admin))
                ->assertStatus(422)
                ->assertJson(['code' => 'altcha_difficulty_invalid']);
        }

        $this->putJson('/api/settings', ['settings' => [['key' => 'altcha_max_number', 'value' => '200000']]], $this->bearer($admin))->assertOk();
        $this->assertSame('200000', Setting::where('key', 'altcha_max_number')->value('value'));
    }

    public function test_the_settings_are_hidden_from_members(): void
    {
        $this->getJson('/api/settings/altcha_max_number', $this->bearer($this->user()))->assertNotFound();
    }
}
