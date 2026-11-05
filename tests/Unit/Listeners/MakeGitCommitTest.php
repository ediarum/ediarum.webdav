<?php

namespace Tests\Unit\Listeners;

use App\Events\DataChange;
use App\Jobs\PushToGitRemote;
use App\Listeners\MakeGitCommit;
use App\Models\Project;
use App\Models\ProjectCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class MakeGitCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_move_commits_with_source_and_destination_in_the_message(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterMove', 'foo.xml', 'bar.xml'));

        Process::assertRan(fn ($process) => str_contains($process->command, 'commit')
            && str_contains($process->command, "'Webdav Change: foo.xml -> bar.xml'"));
        $this->assertSame(1, ProjectCommit::count());
    }

    public function test_a_successful_commit_puts_the_new_head_on_the_event(): void
    {
        Process::fake(fn ($process) => str_contains($process->command, 'rev-parse')
            ? Process::result(output: "abc123\n")
            : Process::result());
        Bus::fake();
        $event = new DataChange('user@example.com', Project::factory()->create(), 'afterCreateFile', 'foo.xml');

        (new MakeGitCommit())->handle($event);

        $this->assertSame('abc123', $event->commit);
    }

    public function test_nothing_to_commit_still_puts_head_on_the_event(): void
    {
        Process::fake(fn ($process) => match (true) {
            str_contains($process->command, 'commit') => Process::result(output: 'nothing to commit, working tree clean', exitCode: 1),
            str_contains($process->command, 'rev-parse') => Process::result(output: "abc123\n"),
            default => Process::result(),
        });
        Bus::fake();
        $event = new DataChange('user@example.com', Project::factory()->create(), 'afterUnbind', 'foo.xml');

        (new MakeGitCommit())->handle($event);

        $this->assertSame('abc123', $event->commit);
    }

    public function test_a_failed_commit_leaves_the_event_without_a_commit(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'boom', exitCode: 1));
        Bus::fake();
        $event = new DataChange('user@example.com', Project::factory()->create(), 'afterCreateFile', 'foo.xml');

        (new MakeGitCommit())->handle($event);

        $this->assertNull($event->commit);
    }

    public function test_successful_commit_with_git_remote_credentials_dispatches_push_to_git_remote_on_the_git_remote_queue(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'ediarum-bot',
            'git_access_token' => 'token-123',
        ]);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertDispatched(PushToGitRemote::class, fn ($job) => $job->queue === 'git-remote');
    }

    public function test_successful_commit_without_git_remote_credentials_does_not_dispatch_but_is_recorded(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create([
            'git_remote_url' => null,
            'git_username' => null,
            'git_access_token' => null,
        ]);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToGitRemote::class);
        $this->assertDatabaseHas('project_commits', ['project_id' => $project->id]);
    }

    public function test_successful_commit_records_a_project_commit_row(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        $this->assertSame(1, $project->commits()->count());
    }

    public function test_successful_commit_records_the_commit_hash(): void
    {
        Process::fake(fn ($process) => str_contains($process->command, 'rev-parse')
            ? Process::result(output: "abc123\n")
            : Process::result());
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        $this->assertSame('abc123', $project->commits()->sole()->commit_hash);
    }

    public function test_failed_commit_does_not_dispatch_or_record(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'boom', exitCode: 1));
        Bus::fake();
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'ediarum-bot',
            'git_access_token' => 'token-123',
        ]);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToGitRemote::class);
        $this->assertSame(0, ProjectCommit::count());
    }

    public function test_a_configured_branch_is_checked_out_before_committing(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create(['git_branch' => 'main']);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Process::assertRan(fn ($process) => str_contains($process->command, 'checkout') && str_contains($process->command, "'main'"));
        Process::assertRan(fn ($process) => str_contains($process->command, 'commit'));
    }

    public function test_a_failed_checkout_logs_an_error_and_never_commits(): void
    {
        Process::fake(fn ($process) => str_contains($process->command, 'checkout')
            ? Process::result(errorOutput: 'no such branch', exitCode: 1)
            : Process::result());
        Bus::fake();
        Log::spy();
        $project = Project::factory()->create(['git_branch' => 'does-not-exist']);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Process::assertNotRan(fn ($process) => str_contains($process->command, 'commit'));
        Bus::assertNotDispatched(PushToGitRemote::class);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_stale_lock_on_checkout_is_removed_and_the_checkout_is_retried(): void
    {
        $repoPath = storage_path('framework/testing/disks/' . Str::random(10));
        File::ensureDirectoryExists($repoPath . '/.git');
        $lockPath = $repoPath . '/.git/HEAD.lock';
        File::put($lockPath, '');

        // The closure re-checks the filesystem on every call: the first "checkout" sees the lock
        // file and fails the way real git would, MakeGitCommit deletes it, and the retry sees it's
        // gone and succeeds -- proving the code under test did the deleting, not the test itself.
        Process::fake(function ($process) use ($lockPath) {
            if (str_contains($process->command, 'checkout') && File::exists($lockPath)) {
                return Process::result(errorOutput: "fatal: Unable to create '{$lockPath}': File exists.", exitCode: 1);
            }

            return Process::result();
        });
        Bus::fake();
        config(['app.repositories_directory' => dirname($repoPath)]);
        $project = Project::factory()->create(['slug' => basename($repoPath)]);

        try {
            (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

            $this->assertFileDoesNotExist($lockPath);
            $this->assertDatabaseHas('project_commits', ['project_id' => $project->id]);
            $this->assertNull($project->fresh()->git_commit_broken_at);
        } finally {
            File::deleteDirectory($repoPath);
        }
    }

    public function test_a_stale_lock_on_commit_is_removed_and_the_commit_is_retried(): void
    {
        $repoPath = storage_path('framework/testing/disks/' . Str::random(10));
        File::ensureDirectoryExists($repoPath . '/.git');
        $lockPath = $repoPath . '/.git/index.lock';
        File::put($lockPath, '');

        Process::fake(function ($process) use ($lockPath) {
            if (str_contains($process->command, 'commit') && File::exists($lockPath)) {
                return Process::result(errorOutput: "fatal: Unable to create '{$lockPath}': File exists.", exitCode: 1);
            }

            return Process::result();
        });
        Bus::fake();
        config(['app.repositories_directory' => dirname($repoPath)]);
        $project = Project::factory()->create(['slug' => basename($repoPath)]);

        try {
            (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

            $this->assertFileDoesNotExist($lockPath);
            $this->assertDatabaseHas('project_commits', ['project_id' => $project->id]);
            $this->assertNull($project->fresh()->git_commit_broken_at);
        } finally {
            File::deleteDirectory($repoPath);
        }
    }

    public function test_a_non_lock_commit_failure_is_not_retried_and_marks_the_project_broken(): void
    {
        Process::fake(fn ($process) => str_contains($process->command, 'commit')
            ? Process::result(errorOutput: 'fatal: unable to write new index file', exitCode: 1)
            : Process::result());
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Process::assertRanTimes(fn ($process) => str_contains($process->command, 'commit'), 1);
        $project->refresh();
        $this->assertNotNull($project->git_commit_broken_at);
        $this->assertStringContainsString('unable to write new index file', $project->git_commit_broken_reason);
    }

    public function test_nothing_to_commit_does_not_mark_the_project_broken(): void
    {
        Process::fake(fn ($process) => str_contains($process->command, 'commit')
            ? Process::result(output: 'nothing to commit, working tree clean', exitCode: 1)
            : Process::result());
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        $this->assertNull($project->fresh()->git_commit_broken_at);
    }

    public function test_a_successful_commit_clears_a_previously_set_broken_flag(): void
    {
        Process::fake();
        Bus::fake();
        $project = Project::factory()->create([
            'git_commit_broken_at' => now()->subDay(),
            'git_commit_broken_reason' => 'some old error',
        ]);

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        $project->refresh();
        $this->assertNull($project->git_commit_broken_at);
        $this->assertNull($project->git_commit_broken_reason);
    }

    public function test_a_timed_out_git_command_is_caught_and_marks_the_project_broken(): void
    {
        Process::fake(fn () => throw $this->timeoutException());
        Bus::fake();
        $project = Project::factory()->create();

        (new MakeGitCommit())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToGitRemote::class);
        $project->refresh();
        $this->assertNotNull($project->git_commit_broken_at);
        $this->assertStringContainsString('timed out', $project->git_commit_broken_reason);
    }

    private function timeoutException(): ProcessTimedOutException
    {
        $symfonyProcess = new SymfonyProcess(['true']);
        $symfonyProcess->setTimeout(15);

        return new ProcessTimedOutException(
            new SymfonyProcessTimedOutException($symfonyProcess, SymfonyProcessTimedOutException::TYPE_GENERAL),
            Process::result(exitCode: 1),
        );
    }
}
