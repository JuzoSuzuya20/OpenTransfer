<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_the_statistics_period_is_bounded(): void
    {
        // Sans borne, days=1000000000 construisait une série d'un milliard de jours
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'is_guest' => false]);

        $started = microtime(true);
        $this->actingAs($admin, 'api')->getJson('/api/stats?days=1000000000')->assertOk();

        $this->assertLessThan(5, microtime(true) - $started);
    }
}
