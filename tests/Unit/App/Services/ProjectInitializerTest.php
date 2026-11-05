<?php

namespace Tests\Unit\App\Services;

use App\Models\Project;
use App\Services\ProjectInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * Real git throughout, nothing mocked -- same style as GitMergeServiceTest: this is exactly the kind
 * of clone/checkout/cleanup behavior where a mocked Process would only prove the mock, not that a
 * real clone, checkout, and directory wipe actually happened.
 */
class ProjectInitializerTest extends TestCase
{
    use RefreshDatabase;

    private string $repositoriesDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoriesDirectory = storage_path('framework/testing/disks/repositories-under-test-' . uniqid());
        config(['app.repositories_directory' => $this->repositoriesDirectory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repositoriesDirectory);

        parent::tearDown();
    }

    public function test_happy_path_clones_checks_out_and_marks_initialized(): void
    {
        $remote = $this->remoteWithDataFolder();
        $project = $this->project($remote);

        $messages = [];
        (new ProjectInitializer())->initialize($project, function (string $m) use (&$messages) {
            $messages[] = $m;
        });

        $project->refresh();
        $this->assertNotNull($project->initialized_at);
        $this->assertTrue(File::isDirectory($project->repository_path . '/.git'));
        $this->assertTrue(File::isDirectory($project->data_folder_location));
        $this->assertNotEmpty($messages);
    }

    public function test_a_branch_that_does_not_exist_throws_leaves_initialized_at_null_and_removes_the_clone(): void
    {
        $remote = $this->remoteWithDataFolder();
        $project = $this->project($remote, 'does-not-exist');

        try {
            (new ProjectInitializer())->initialize($project);
            $this->fail('Expected a RuntimeException for a missing branch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'does-not-exist' does not exist", $e->getMessage());
        }

        $this->assertNull($project->fresh()->initialized_at);
        $this->assertFalse(File::isDirectory($project->repository_path));
    }

    public function test_a_repository_with_no_top_level_data_folder_throws_and_removes_the_clone(): void
    {
        $remote = storage_path('framework/testing/disks/no-data-remote-' . uniqid());
        $this->initGitRepo($remote);
        file_put_contents($remote . '/readme.txt', 'no data folder here');
        Process::path($remote)->run('git add readme.txt')->throw();
        Process::path($remote)->run('git commit -q -m "add readme"')->throw();

        $project = $this->project($remote);

        try {
            (new ProjectInitializer())->initialize($project);
            $this->fail("Expected a RuntimeException for a missing 'data' folder.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("top-level 'data' folder", $e->getMessage());
        }

        $this->assertNull($project->fresh()->initialized_at);
        $this->assertFalse(File::isDirectory($project->repository_path));
    }

    public function test_retrying_after_a_failure_succeeds_cleanly(): void
    {
        $remote = storage_path('framework/testing/disks/retry-remote-' . uniqid());
        $this->initGitRepo($remote);
        Process::path($remote)->run('git checkout -q -b feature')->throw();
        Process::path($remote)->run('git checkout -q main')->throw();

        $project = $this->project($remote, 'feature');

        try {
            (new ProjectInitializer())->initialize($project);
            $this->fail('Expected the first attempt to fail (feature has no data folder yet).');
        } catch (RuntimeException) {
        }
        $this->assertNull($project->fresh()->initialized_at);

        // Fix the underlying problem (checkout a branch that now has a `data` folder) and retry --
        // proves the wipe-before-clone step in ProjectInitializer actually makes a retry safe rather
        // than merging onto whatever the failed attempt left behind.
        $project->git_branch = 'main';
        $project->save();

        File::ensureDirectoryExists($remote . '/data');
        file_put_contents($remote . '/data/.gitkeep', '');
        Process::path($remote)->run('git add data')->throw();
        Process::path($remote)->run('git commit -q -m "add data folder"')->throw();

        (new ProjectInitializer())->initialize($project);

        $this->assertNotNull($project->fresh()->initialized_at);
        $this->assertTrue(File::isDirectory($project->data_folder_location));
    }

    public function test_a_bad_git_remote_url_throws_surfacing_gits_own_error(): void
    {
        $project = $this->project(storage_path('framework/testing/disks/does-not-exist-' . uniqid()));

        try {
            (new ProjectInitializer())->initialize($project);
            $this->fail('Expected a RuntimeException for an unreachable remote.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not clone the repository', $e->getMessage());
        }

        $this->assertNull($project->fresh()->initialized_at);
    }

    public function test_progress_callback_is_invoked_once_per_step_in_order(): void
    {
        $remote = $this->remoteWithDataFolder();
        $project = $this->project($remote);

        $messages = [];
        (new ProjectInitializer())->initialize($project, function (string $m) use (&$messages) {
            $messages[] = $m;
        });

        $this->assertStringContainsString('Cloning', $messages[0]);
        $this->assertStringContainsString('cloned', $messages[1]);
        $this->assertStringContainsString('Checking out', $messages[2]);
        $this->assertStringContainsString('checked out', $messages[3]);
        $this->assertStringContainsString('Verifying', $messages[4]);
    }

    private function project(string $remoteUrl, string $branch = 'main'): Project
    {
        return Project::factory()->uninitialized()->create([
            'git_branch' => $branch,
            'git_remote_url' => $remoteUrl,
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);
    }

    private function remoteWithDataFolder(): string
    {
        $remote = storage_path('framework/testing/disks/init-remote-' . uniqid());
        $this->initGitRepo($remote);
        File::ensureDirectoryExists($remote . '/data');
        file_put_contents($remote . '/data/.gitkeep', '');
        Process::path($remote)->run('git add data')->throw();
        Process::path($remote)->run('git commit -q -m "add data folder"')->throw();

        return $remote;
    }

    private function initGitRepo(string $path): void
    {
        File::ensureDirectoryExists($path);
        Process::path($path)->run('git init -q -b main')->throw();
        Process::path($path)->run('git config user.email "test@example.com"')->throw();
        Process::path($path)->run('git config user.name "Test"')->throw();
        Process::path($path)->run('git commit -q --allow-empty -m base')->throw();
    }
}
