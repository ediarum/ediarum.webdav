<?php

namespace Tests\Feature\Dev;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The local-only standalone page (DevStandaloneController): a 404 outside APP_ENV=local, and it
 * must never log anyone in there.
 */
class StandaloneDevPageTest extends TestCase
{
    use RefreshDatabase;

    private function asEnvironment(string $env): void
    {
        $this->app['env'] = $env;
    }

    public function test_it_is_a_404_in_production_and_logs_no_one_in(): void
    {
        $this->asEnvironment('production');
        User::factory()->create(['is_admin' => true]);

        $this->get('/webdav/')->assertNotFound();
        $this->get('/webdav/projects/1')->assertNotFound();
        $this->get('/')->assertNotFound();

        $this->assertGuest();
    }

    public function test_it_is_a_404_in_the_testing_environment(): void
    {
        User::factory()->create(['is_admin' => true]);

        $this->get('/webdav/')->assertNotFound();

        $this->assertGuest();
    }

    public function test_locally_it_logs_a_guest_in_as_the_first_admin_and_mounts_embed_js(): void
    {
        $this->asEnvironment('local');
        User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'First Admin']);
        User::factory()->create(['is_admin' => true]);

        $response = $this->get('/webdav/projects/1');

        $response->assertOk();
        $response->assertSee("import('/apps/webdav/embed.js')", false);
        $response->assertSee('"routerBase":"\/webdav\/"', false);
        $response->assertSee('"name":"First Admin","roles":["admin"]', false);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_locally_an_existing_session_is_kept(): void
    {
        $this->asEnvironment('local');
        User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/webdav/')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_locally_without_an_admin_it_says_to_seed(): void
    {
        $this->asEnvironment('local');

        $response = $this->get('/webdav/');

        $response->assertStatus(500);
        $response->assertSee('php artisan db:seed');
        $this->assertGuest();
    }

    public function test_locally_the_root_redirects_to_the_page(): void
    {
        $this->asEnvironment('local');

        $this->get('/')->assertRedirect('/webdav/');
    }

    public function test_it_does_not_shadow_the_webdav_endpoint_or_the_api(): void
    {
        $this->asEnvironment('local');
        User::factory()->create(['is_admin' => true]);

        $this->get('/api/webdav/projects')->assertUnauthorized();
        $this->assertGuest();
    }
}
