<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_maintenance_mode_refuses_when_locks_exist(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();

        DB::table("locks_{$project->id}")->insert([
            'owner' => 'someone@example.com',
            'timeout' => 300,
            'created' => time(),
            'token' => 'opaquelocktoken:test-token',
            'scope' => 1,
            'depth' => 0,
            'uri' => 'foo.xml',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('projects.enable-maintenance-mode', ['projectId' => $project->id]));

        $response->assertStatus(409);
        $response->assertJsonStructure(['error']);
        $this->assertFalse((bool) $project->fresh()->is_in_maintenance_mode);
    }

    public function test_enable_maintenance_mode_succeeds_without_locks(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();

        $response = $this->actingAs($admin)
            ->post(route('projects.enable-maintenance-mode', ['projectId' => $project->id]));

        $response->assertOk();
        $response->assertJson(['isInMaintenanceMode' => true]);
        $this->assertTrue((bool) $project->fresh()->is_in_maintenance_mode);
    }

    public function test_enable_maintenance_mode_refuses_for_an_uninitialized_project(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['initialized_at' => null]);

        $response = $this->actingAs($admin)
            ->post(route('projects.enable-maintenance-mode', ['projectId' => $project->id]));

        $response->assertStatus(409);
        $this->assertFalse((bool) $project->fresh()->is_in_maintenance_mode);
    }

    public function test_disable_maintenance_mode(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['is_in_maintenance_mode' => true]);

        $response = $this->actingAs($admin)
            ->post(route('projects.disable-maintenance-mode', ['projectId' => $project->id]));

        $response->assertOk();
        $response->assertJson(['isInMaintenanceMode' => false]);
        $this->assertFalse((bool) $project->fresh()->is_in_maintenance_mode);
    }

    /**
     * WebDavController and ProjectServicesController::enableMaintenanceMode both resolve the lock
     * table name via LockTableManager::tableNameFor() -- this proves they actually agree on which
     * table that is, by taking a lock through the real WebDAV route and checking the maintenance-mode
     * guard sees it, rather than just asserting the two call sites build the same string.
     */
    public function test_a_lock_taken_through_webdav_blocks_maintenance_mode_for_the_same_project(): void
    {
        $editor = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($editor);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($project->data_folder_location);
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        $server = $this->transformHeadersToServerVars(['Content-Type' => 'application/xml']);
        $server['PHP_AUTH_USER'] = $editor->email;
        $server['PHP_AUTH_PW'] = 'password';
        $lockBody = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<D:lockinfo xmlns:D="DAV:">
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
  <D:owner><D:href>test-owner</D:href></D:owner>
</D:lockinfo>
XML;
        $lockResponse = $this->call('LOCK', "/connection/{$project->slug}/foo.xml", [], [], [], $server, $lockBody);
        $lockResponse->assertStatus(200);

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->post(route('projects.enable-maintenance-mode', ['projectId' => $project->id]));

        $response->assertStatus(409);
        $this->assertFalse((bool) $project->fresh()->is_in_maintenance_mode);

        \Illuminate\Support\Facades\File::deleteDirectory($project->data_folder_location);
    }
}
