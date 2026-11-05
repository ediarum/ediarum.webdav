<?php

namespace Tests\Unit\App\Services;

use App\Models\Project;
use App\Services\GitMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * Real repos throughout, nothing mocked -- this is exactly the kind of history-shape logic (ff vs.
 * up-to-date vs. diverged) that's easy to get subtly wrong, and where a mocked test would only prove
 * the mock, not that `git merge-base --is-ancestor` was actually reasoned about correctly.
 */
class GitMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_classify_is_ff_when_the_local_branch_is_a_strict_ancestor_of_the_other_branch(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');

        $project = $this->project($remote, $local);

        $result = (new GitMergeService())->classify($project, 'feature');

        $this->assertSame('ff', $result['status']);
        $this->assertSame(1, $result['commitCount']);
        $this->assertSame([['status' => 'A', 'path' => 'foo.txt', 'previousPath' => null]], $result['changedFiles']);
    }

    /**
     * A tracked branch that doesn't exist locally (typo'd in Settings, or a repo whose default
     * branch is actually 'master') must fail with a clear, actionable message -- not the raw
     * ProcessFailedException dump `git rev-parse` produces for an ambiguous/unknown ref.
     */
    public function test_classify_gives_a_clear_error_when_the_tracked_branch_does_not_exist_locally(): void
    {
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');

        config(['app.repositories_directory' => dirname($local)]);
        $project = Project::factory()->create([
            'slug' => basename($local),
            'git_branch' => 'does-not-exist',
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);

        try {
            (new GitMergeService())->classify($project, 'feature');
            $this->fail('Expected a RuntimeException for a missing tracked branch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'does-not-exist' does not exist", $e->getMessage());
            $this->assertStringNotContainsString('Exit Code', $e->getMessage());
        }
    }

    public function test_classify_is_up_to_date_when_the_other_branch_is_behind_local(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        Process::path($remote)->run('git branch behind')->throw();
        $this->commitFile($local, 'bar.txt', 'hi');

        $project = $this->project($remote, $local);

        $result = (new GitMergeService())->classify($project, 'behind');

        $this->assertSame('up_to_date', $result['status']);
    }

    public function test_classify_is_up_to_date_when_the_other_branch_equals_local(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $project = $this->project($remote, $local);

        $result = (new GitMergeService())->classify($project, 'main');

        $this->assertSame('up_to_date', $result['status']);
    }

    public function test_classify_is_diverged_when_neither_branch_is_an_ancestor_of_the_other(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $this->commitFile($remote, 'remote-only.txt', 'x', 'other');
        $this->commitFile($local, 'local-only.txt', 'y');

        $project = $this->project($remote, $local);

        $result = (new GitMergeService())->classify($project, 'other');

        $this->assertSame('diverged', $result['status']);
    }

    public function test_merge_fast_forwards_the_tracked_branch_and_reports_changed_files(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');
        $featureSha = trim(Process::path($remote)->run('git rev-parse feature')->output());

        $project = $this->project($remote, $local);

        $result = (new GitMergeService())->merge($project, 'feature');

        $this->assertSame($featureSha, $result['after']);
        $this->assertSame([['status' => 'A', 'path' => 'foo.txt', 'previousPath' => null]], $result['changes']);

        $localMainSha = trim(Process::path($local)->run('git rev-parse main')->output());
        $this->assertSame($featureSha, $localMainSha);

        $branch = trim(Process::path($local)->run('git rev-parse --abbrev-ref HEAD')->output());
        $this->assertSame('main', $branch);

        $this->assertScratchRefIsGone($local);
    }

    public function test_merge_refuses_and_leaves_the_repo_untouched_when_diverged(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $this->commitFile($remote, 'remote-only.txt', 'x', 'other');
        $this->commitFile($local, 'local-only.txt', 'y');

        $project = $this->project($remote, $local);
        $beforeSha = trim(Process::path($local)->run('git rev-parse main')->output());

        try {
            (new GitMergeService())->merge($project, 'other');
            $this->fail('Expected a RuntimeException for a diverged merge.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('diverged', $e->getMessage());
        }

        $afterSha = trim(Process::path($local)->run('git rev-parse main')->output());
        $this->assertSame($beforeSha, $afterSha, 'diverged merge must not move the tracked branch');
        $this->assertScratchRefIsGone($local);
    }

    /**
     * Simulates the race B4/step 5 guards against: the pre-check classifies the merge as `ff`, but
     * the actual `merge --ff-only` fails anyway (e.g. someone pushed to the tracked branch through
     * another path in between). Fakes only the `git merge` command -- every other command in this
     * test still runs for real, so the classification the failure races against is genuine.
     */
    public function test_merge_aborts_cleanly_when_the_fast_forward_itself_fails_despite_passing_the_precheck(): void
    {
        [$remote, $local] = $this->remoteAndLocal();

        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');

        $project = $this->project($remote, $local);
        $beforeSha = trim(Process::path($local)->run('git rev-parse main')->output());

        Process::fake([
            '*merge --ff-only*' => Process::result(errorOutput: 'not possible to fast-forward', exitCode: 1),
        ]);

        try {
            (new GitMergeService())->merge($project, 'feature');
            $this->fail('Expected a RuntimeException when the fast-forward itself fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not add those commits', $e->getMessage());
        }

        Process::assertRan(fn ($process) => str_contains($process->command, 'merge --ff-only'));

        $afterSha = trim(Process::path($local)->run('git rev-parse main')->output());
        $this->assertSame($beforeSha, $afterSha, 'a failed fast-forward must not move the tracked branch');
        $this->assertScratchRefIsGone($local);
    }

    private function assertScratchRefIsGone(string $repo): void
    {
        $check = Process::path($repo)->run('git rev-parse --verify ' . escapeshellarg(GitMergeService::SCRATCH_REF));
        $this->assertTrue($check->failed(), 'expected the scratch ref to be cleaned up');
    }

    public function test_a_process_level_failure_listing_branches_does_not_leak_the_token(): void
    {
        [$remote, $local] = $this->remoteAndLocal();
        config(['app.repositories_directory' => dirname($local)]);
        $project = Project::factory()->create([
            'slug' => basename($local),
            'git_branch' => 'main',
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'leaked-token-xyz',
        ]);

        // Simulates what a real timeout looks like: GitCommandBuilder::lsRemoteHeads() embeds the
        // token in the remote URL, and Symfony Process's own timeout exception message would
        // contain the full command line verbatim -- GitMergeService::runAuthenticated() must never
        // let that reach the caller unfiltered.
        Process::fake(fn () => new RuntimeException(
            "The process \"'git' ls-remote --heads 'https://user:leaked-token-xyz@{$remote}'\" exceeded the timeout of 15 seconds."
        ));

        try {
            (new GitMergeService())->listBranches($project);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('leaked-token-xyz', $e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string} [$remotePath, $localPath] -- $localPath is a real clone of
     * $remotePath, both starting with a single "base" commit on `main`.
     */
    private function remoteAndLocal(): array
    {
        $remote = storage_path('framework/testing/disks/git-merge-remote-' . uniqid());
        $local = storage_path('framework/testing/disks/git-merge-local-' . uniqid());
        $this->tempDirs[] = $remote;
        $this->tempDirs[] = $local;

        File::ensureDirectoryExists($remote);
        Process::path($remote)->run('git init -q -b main')->throw();
        Process::path($remote)->run('git config user.email "test@example.com"')->throw();
        Process::path($remote)->run('git config user.name "Test"')->throw();
        Process::path($remote)->run('git commit --allow-empty -m base')->throw();

        Process::run('git clone -q ' . escapeshellarg($remote) . ' ' . escapeshellarg($local))->throw();
        Process::path($local)->run('git config user.email "test@example.com"')->throw();
        Process::path($local)->run('git config user.name "Test"')->throw();

        return [$remote, $local];
    }

    private function commitFile(string $repo, string $file, string $contents, ?string $branch = null): void
    {
        if ($branch !== null) {
            Process::path($repo)->run('git checkout -q -b ' . escapeshellarg($branch))->throw();
        }

        file_put_contents($repo . '/' . $file, $contents);
        Process::path($repo)->run('git add ' . escapeshellarg($file))->throw();
        Process::path($repo)->run('git commit -q -m ' . escapeshellarg("add {$file}"))->throw();
    }

    private function project(string $remote, string $local): Project
    {
        config(['app.repositories_directory' => dirname($local)]);

        return Project::factory()->create([
            'slug' => basename($local),
            'git_branch' => 'main',
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);
    }
}
