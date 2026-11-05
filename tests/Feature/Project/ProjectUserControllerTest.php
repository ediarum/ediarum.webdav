<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_cannot_add_a_user_to_a_project(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = Project::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('projects.add-user'), [
            'project_id' => $project->id,
            'user_id' => $other->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id, 'user_id' => $other->id]);
    }

    public function test_an_admin_can_add_a_user_to_a_project(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($admin)->postJson(route('projects.add-user'), [
            'project_id' => $project->id,
            'user_id' => $other->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $other->id]);
    }

    public function test_an_admin_can_remove_a_user_from_a_project(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();
        $other = User::factory()->create();
        $project->users()->attach($other->id);

        $response = $this->actingAs($admin)->deleteJson(route('projects.remove-user'), [
            'project_id' => $project->id,
            'user_id' => $other->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id, 'user_id' => $other->id]);
    }

    public function test_a_non_admin_cannot_remove_a_user_from_a_project(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = Project::factory()->create();
        $other = User::factory()->create();
        $project->users()->attach($other->id);

        $response = $this->actingAs($user)->deleteJson(route('projects.remove-user'), [
            'project_id' => $project->id,
            'user_id' => $other->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $other->id]);
    }
}
