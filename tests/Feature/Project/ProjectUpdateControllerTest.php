<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ProjectController::update()'s handling of git_credentials_broken_at specifically --
 * before this, the flag only ever cleared on the *next successful push* (PushToGitRemote::handle()),
 * so fixing a broken token here left the admin panel and project page showing it as still broken
 * until another WebDAV write happened to trigger a push. Saving the form now clears it as soon as
 * the admin actually changes either credential field.
 */
class ProjectUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'name' => $project->name,
            'slug' => $project->slug,
            'git_remote_url' => $project->git_remote_url,
            'git_username' => $project->git_username,
            'git_branch' => $project->git_branch,
        ], $overrides);
    }

    public function test_changing_the_git_username_clears_the_master_fallback_flag(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create([
            'git_username' => 'old-user',
            'git_credentials_broken_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)->put(
            route('projects.update', $project),
            $this->validPayload($project, ['git_username' => 'new-user'])
        );

        $this->assertNull($project->fresh()->git_credentials_broken_at);
    }

    public function test_entering_a_new_git_access_token_clears_the_master_fallback_flag(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create([
            'git_username' => 'user',
            'git_access_token' => 'old-token',
            'git_credentials_broken_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)->put(
            route('projects.update', $project),
            $this->validPayload($project, ['git_access_token' => 'new-token'])
        );

        $this->assertNull($project->fresh()->git_credentials_broken_at);
    }

    public function test_saving_without_changing_git_remote_credentials_leaves_the_flag_alone(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create([
            'git_username' => 'user',
            'git_credentials_broken_at' => now()->subHour(),
        ]);
        $brokenAt = $project->git_credentials_broken_at;

        $this->actingAs($admin)->put(
            route('projects.update', $project),
            $this->validPayload($project, ['name' => 'Renamed Project'])
        );

        $fresh = $project->fresh();
        $this->assertNotNull($fresh->git_credentials_broken_at);
        $this->assertEquals($brokenAt->timestamp, $fresh->git_credentials_broken_at->timestamp);
    }

    public function test_a_non_admin_cannot_update_a_project(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = Project::factory()->create();

        $response = $this->actingAs($user)->put(
            route('projects.update', $project),
            $this->validPayload($project)
        );

        $response->assertForbidden();
    }

    /**
     * Slugs are permanent (ediarum.platform docs/auth-contract.md, "Projects"), also for projects
     * that were never initialized.
     */
    public function test_the_slug_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([now(), null] as $initializedAt) {
            $project = Project::factory()->create(['initialized_at' => $initializedAt]);
            $slug = $project->slug;

            $this->actingAs($admin)->putJson(
                route('projects.update', $project),
                $this->validPayload($project, ['slug' => 'something-else'])
            )->assertStatus(422)->assertJsonValidationErrors('slug');

            $this->assertSame($slug, $project->fresh()->slug);
        }
    }

    public function test_the_name_can_change_while_the_slug_is_kept_or_omitted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['slug' => 'kept']);

        $this->actingAs($admin)->putJson(route('projects.update', $project), $this->validPayload($project, ['name' => 'First']))
            ->assertOk();

        $payload = $this->validPayload($project, ['name' => 'Second']);
        unset($payload['slug']);
        $this->actingAs($admin)->putJson(route('projects.update', $project), $payload)->assertOk();

        $fresh = $project->fresh();
        $this->assertSame('Second', $fresh->name);
        $this->assertSame('kept', $fresh->slug);
    }

    public function test_the_ediarum_services_switch_can_be_turned_on_and_off(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();
        $this->assertFalse($project->fresh()->push_to_ediarum_services);

        $this->actingAs($admin)->putJson(route('projects.update', $project), $this->validPayload($project, ['push_to_ediarum_services' => true]))
            ->assertOk();
        $this->assertTrue($project->fresh()->push_to_ediarum_services);

        $this->actingAs($admin)->putJson(route('projects.update', $project), $this->validPayload($project, ['push_to_ediarum_services' => false]))
            ->assertOk();
        $this->assertFalse($project->fresh()->push_to_ediarum_services);
    }

    public function test_the_index_source_of_truth_flag_can_be_turned_on_and_off(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();
        $this->assertFalse($project->fresh()->is_index_app_source_of_truth);

        $this->actingAs($admin)->putJson(route('projects.update', $project), $this->validPayload($project, ['is_index_app_source_of_truth' => true]))
            ->assertOk();
        $this->assertTrue($project->fresh()->is_index_app_source_of_truth);

        $this->actingAs($admin)->putJson(route('projects.update', $project), $this->validPayload($project, ['is_index_app_source_of_truth' => false]))
            ->assertOk();
        $this->assertFalse($project->fresh()->is_index_app_source_of_truth);
    }
}
