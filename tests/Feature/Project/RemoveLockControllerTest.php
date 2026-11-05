<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoveLockControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_force_unlock_a_webdav_lock(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();

        $lockId = DB::table("locks_{$project->id}")->insertGetId([
            'owner' => 'someone@example.com',
            'timeout' => 300,
            'created' => time(),
            'token' => 'opaquelocktoken:test-token',
            'scope' => 1,
            'depth' => 0,
            'uri' => 'foo.xml',
        ]);

        $response = $this->actingAs($admin)
            ->deleteJson(route('projects.remove-lock', ['projectId' => $project->id, 'lockId' => $lockId]));

        $response->assertOk();
        $this->assertDatabaseMissing("locks_{$project->id}", ['id' => $lockId]);
    }

    public function test_a_non_admin_cannot_force_unlock_a_webdav_lock(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = Project::factory()->create();

        $lockId = DB::table("locks_{$project->id}")->insertGetId([
            'owner' => 'someone@example.com',
            'timeout' => 300,
            'created' => time(),
            'token' => 'opaquelocktoken:test-token',
            'scope' => 1,
            'depth' => 0,
            'uri' => 'foo.xml',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson(route('projects.remove-lock', ['projectId' => $project->id, 'lockId' => $lockId]));

        $response->assertForbidden();
        $this->assertDatabaseHas("locks_{$project->id}", ['id' => $lockId]);
    }
}
