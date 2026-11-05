<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectLockedPath;
use App\Models\User;
use App\Services\LockTableManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LockedPathControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::factory()->create();
        File::ensureDirectoryExists($this->project->data_folder_location . '/folder');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project->data_folder_location);

        parent::tearDown();
    }

    public function test_a_non_admin_cannot_lock_a_path(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => 'folder',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('project_locked_paths', 0);
    }

    public function test_an_admin_can_lock_a_path(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => 'folder',
        ]);

        $response->assertOk();
        $response->assertJson(['path' => 'folder', 'lockedBy' => $this->admin->email]);
        $this->assertDatabaseHas('project_locked_paths', [
            'project_id' => $this->project->id,
            'path' => 'folder',
            'locked_by' => $this->admin->email,
        ]);
    }

    public function test_leading_and_trailing_slashes_are_trimmed_from_the_path(): void
    {
        $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => '/folder/',
        ]);

        $this->assertDatabaseHas('project_locked_paths', ['path' => 'folder']);
    }

    public function test_a_path_that_escapes_the_project_directory_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => '../../etc',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
        $this->assertDatabaseCount('project_locked_paths', 0);
    }

    public function test_a_path_already_covered_by_an_existing_lock_is_rejected(): void
    {
        ProjectLockedPath::factory()->create(['project_id' => $this->project->id, 'path' => 'folder']);

        $response = $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => 'folder',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
        $this->assertDatabaseCount('project_locked_paths', 1);
    }

    public function test_locking_a_path_with_an_active_editing_lock_underneath_it_is_rejected(): void
    {
        LockTableManager::ensureTableForProject($this->project->id);
        DB::table(LockTableManager::tableNameFor($this->project->id))->insert([
            'owner' => 'user@example.com',
            'timeout' => 1800,
            'created' => time(),
            'token' => 'urn:uuid:' . \Illuminate\Support\Str::uuid(),
            'scope' => 1,
            'depth' => 0,
            'uri' => 'folder/file.xml',
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), [
            'path' => 'folder',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
        $this->assertDatabaseCount('project_locked_paths', 0);
    }

    public function test_a_missing_path_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('projects.lock-path', ['projectId' => $this->project->id]), []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_an_admin_can_unlock_a_path(): void
    {
        $lockedPath = ProjectLockedPath::factory()->create(['project_id' => $this->project->id, 'path' => 'folder']);

        $response = $this->actingAs($this->admin)->deleteJson(route('projects.unlock-path', [
            'projectId' => $this->project->id,
            'lockedPathId' => $lockedPath->id,
        ]));

        $response->assertOk();
        $this->assertDatabaseMissing('project_locked_paths', ['id' => $lockedPath->id]);
    }

    public function test_a_non_admin_cannot_unlock_a_path(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $lockedPath = ProjectLockedPath::factory()->create(['project_id' => $this->project->id, 'path' => 'folder']);

        $response = $this->actingAs($user)->deleteJson(route('projects.unlock-path', [
            'projectId' => $this->project->id,
            'lockedPathId' => $lockedPath->id,
        ]));

        $response->assertForbidden();
        $this->assertDatabaseHas('project_locked_paths', ['id' => $lockedPath->id]);
    }
}
