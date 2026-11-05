<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets into /api/webdav/…, and what the SPA sees when they don't.
 */
class AppApiAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No Accept header on purpose: EventSource can't send one, and the SPA has to see a 401 (to
     * show the login) rather than follow a redirect to an HTML page.
     */
    public function test_an_unauthenticated_request_is_a_401_json_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/webdav/projects');

        $response->assertUnauthorized();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_an_unauthenticated_sse_stream_is_a_401(): void
    {
        $project = Project::factory()->create();

        $this->get("/api/webdav/project/{$project->id}/retry-failed-pushes")->assertUnauthorized();
    }

    public function test_a_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/api/webdav/projects');

        $response->assertForbidden();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_ping_is_a_204_for_an_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/api/webdav/ping')->assertNoContent();
    }

    public function test_config_reports_whether_the_admin_panel_is_enabled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        config(['app.admin_panel_enabled' => false]);
        $this->actingAs($admin)->getJson('/api/webdav/config')
            ->assertExactJson(['adminPanelEnabled' => false]);

        config(['app.admin_panel_enabled' => true]);
        $this->actingAs($admin)->getJson('/api/webdav/config')
            ->assertExactJson(['adminPanelEnabled' => true]);
    }

    public function test_an_unknown_project_is_a_404_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/api/webdav/projects/999999');

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
    }

    /**
     * The UI is the portal's: Laravel serves only the API, the embed build and WebDAV, so the old
     * Blade and Breeze pages are gone.
     */
    public function test_laravel_serves_no_html_pages_any_more(): void
    {
        foreach (['/', '/login', '/register', '/profile', '/projects', '/users', '/admin', '/faq'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
