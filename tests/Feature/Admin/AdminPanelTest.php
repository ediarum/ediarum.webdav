<?php

namespace Tests\Feature\Admin;

use App\Jobs\PushToGitRemote;
use App\Models\Project;
use App\Models\ProjectCommit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The failed_jobs row shape here (payload's data.command as a real `serialize($job)` string, wrapped
 * in the exact JSON keys Laravel's database queue driver writes) was confirmed by actually dispatching
 * a PushToGitRemote job through a real database queue connection and inspecting the resulting row --
 * not guessed from Laravel's source. AdminController::index() decodes this the same way
 * ProjectController::show() already does for eXist-db failures.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_cannot_view_the_admin_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.index'));

        $response->assertForbidden();
    }

    public function test_an_admin_can_view_the_admin_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
    }

    public function test_the_kill_switch_takes_the_panel_out_of_service_even_for_an_admin(): void
    {
        config(['app.admin_panel_enabled' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertNotFound();
    }

    public function test_it_lists_recent_commits_within_the_default_window(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['name' => 'Active Project']);
        ProjectCommit::factory()->create(['project_id' => $project->id, 'committed_at' => now()->subHours(2)]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonPath('recentCommits.0.project.name', 'Active Project');
    }

    public function test_it_excludes_commits_older_than_the_requested_window(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['name' => 'Old Commit Project']);
        ProjectCommit::factory()->create(['project_id' => $project->id, 'committed_at' => now()->subHours(10)]);

        $response = $this->actingAs($admin)->get(route('admin.index', ['hours' => 5]));

        $response->assertOk();
        $response->assertJsonPath('commitHours', 5);
        $response->assertJsonCount(0, 'recentCommits');
    }

    public function test_it_orders_recent_commits_newest_first(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $older = Project::factory()->create(['name' => 'Older Commit Project']);
        $newer = Project::factory()->create(['name' => 'Newer Commit Project']);
        ProjectCommit::factory()->create(['project_id' => $older->id, 'committed_at' => now()->subHours(3)]);
        ProjectCommit::factory()->create(['project_id' => $newer->id, 'committed_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $this->assertSame(
            ['Newer Commit Project', 'Older Commit Project'],
            $response->json('recentCommits.*.project.name')
        );
    }

    public function test_recent_commits_link_their_hash_to_the_commit_on_the_git_remote(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['git_remote_url' => 'https://git.example.org/group/repo.git']);
        ProjectCommit::factory()->create(['project_id' => $project->id, 'commit_hash' => 'abc123']);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertJsonPath('recentCommits.0.commitHash', 'abc123');
        $response->assertJsonPath('recentCommits.0.commitUrl', 'https://git.example.org/group/repo/-/commit/abc123');
    }

    public function test_a_recent_commit_without_a_hash_or_git_remote_has_no_link(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $withoutRemote = Project::factory()->create(['git_remote_url' => null]);
        ProjectCommit::factory()->create(['project_id' => $withoutRemote->id, 'commit_hash' => 'abc123', 'committed_at' => now()]);
        ProjectCommit::factory()->create(['project_id' => Project::factory()->create()->id, 'committed_at' => now()->subHour()]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertJsonPath('recentCommits.0.commitHash', 'abc123');
        $response->assertJsonPath('recentCommits.0.commitUrl', null);
        $response->assertJsonPath('recentCommits.1.commitHash', null);
        $response->assertJsonPath('recentCommits.1.commitUrl', null);
    }

    public function test_it_lists_failed_git_remote_pushes_with_their_project_and_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['name' => 'Broken Project']);
        $this->insertFailedGitRemotePush($project, "Push to Git remote failed: remote: HTTP Basic: Access denied");

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonPath('failedGitRemotePushes.0.project.name', 'Broken Project');
        $this->assertStringContainsString(
            'Push to Git remote failed: remote: HTTP Basic: Access denied',
            $response->json('failedGitRemotePushes.0.reason')
        );
    }

    public function test_it_does_not_list_failures_from_other_queues(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['name' => 'Exist Db Project']);
        $this->insertFailedGitRemotePush($project, 'irrelevant', queue: 'exist-db');

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonCount(0, 'failedGitRemotePushes');
    }

    public function test_it_orders_by_most_recently_failed_first(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $older = Project::factory()->create(['name' => 'Older Failure']);
        $newer = Project::factory()->create(['name' => 'Newer Failure']);
        $this->insertFailedGitRemotePush($older, 'boom', failedAt: now()->subDay());
        $this->insertFailedGitRemotePush($newer, 'boom', failedAt: now());

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $this->assertSame(
            ['Newer Failure', 'Older Failure'],
            $response->json('failedGitRemotePushes.*.project.name')
        );
    }

    public function test_it_lists_projects_currently_using_the_master_git_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create([
            'name' => 'Fallback Project',
            'git_credentials_broken_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonPath('projectsUsingMasterFallback.0.project.name', 'Fallback Project');
    }

    public function test_it_does_not_list_projects_without_the_master_fallback_flag_set(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create(['name' => 'Healthy Project']);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonCount(0, 'projectsUsingMasterFallback');
    }

    public function test_it_lists_projects_currently_failing_to_commit_with_their_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create([
            'name' => 'Stuck Project',
            'git_commit_broken_at' => now()->subHour(),
            'git_commit_broken_reason' => "fatal: Unable to create '/data/stuck/.git/index.lock': File exists.",
        ]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonPath('projectsWithBrokenCommits.0.project.name', 'Stuck Project');
        $response->assertJsonPath(
            'projectsWithBrokenCommits.0.reason',
            "fatal: Unable to create '/data/stuck/.git/index.lock': File exists."
        );
    }

    public function test_it_does_not_list_projects_without_the_broken_commit_flag_set(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create(['name' => 'Committing Fine Project']);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk();
        $response->assertJsonCount(0, 'projectsWithBrokenCommits');
    }

    private function insertFailedGitRemotePush(Project $project, string $exceptionMessage, string $queue = 'git-remote', $failedAt = null): void
    {
        $job = new PushToGitRemote($project);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => PushToGitRemote::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => [
                    'commandName' => PushToGitRemote::class,
                    'command' => serialize($job),
                ],
                'createdAt' => now()->timestamp,
                'delay' => null,
            ]),
            'exception' => "Exception: {$exceptionMessage} in /var/www/webdav/app/Jobs/PushToGitRemote.php:50\nStack trace:\n#0 {main}",
            'failed_at' => $failedAt ?? now(),
        ]);
    }
}
