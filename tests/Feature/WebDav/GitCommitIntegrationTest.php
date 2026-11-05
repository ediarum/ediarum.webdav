<?php

namespace Tests\Feature\WebDav;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Everything in tests/Unit/Listeners/MakeGitCommitTest.php fakes Process, which proves the *right
 * command string* gets built but not that the string actually does the right thing when a real
 * shell and a real git binary run it. This test runs the real listener against a real git repo, with
 * nothing faked, so it can catch the class of bug Process::fake() structurally cannot: unescaped
 * spaces/special characters in repository_path or the commit message breaking the shell command.
 */
class GitCommitIntegrationTest extends WebDavTestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_a_real_webdav_write_produces_a_real_git_commit(): void
    {
        // Deliberately include a space in the repo path (via the slug -- repository_path is
        // `{repositories_directory}/{slug}`) -- this is exactly what MakeGitCommit/PushToGitRemote's
        // unescaped `"cd $dataRepo && ..."` string interpolation gets wrong, and Process::fake() can
        // never catch since it never actually runs a shell.
        [$project, $repoPath] = $this->projectWithRealRepo();
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->initGitRepo($repoPath);

        $response = $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project);

        $response->assertStatus(201);

        $log = Process::path($repoPath)->run('git log --oneline -1');
        $this->assertTrue($log->successful(), 'git log failed: ' . $log->errorOutput());
        $this->assertStringContainsString('Webdav Change: foo.xml', $log->output());

        $status = Process::path($repoPath)->run('git status --porcelain');
        $this->assertSame('', trim($status->output()), 'expected a clean working tree after the commit');
    }

    /**
     * Proves Part A's checkout enforcement actually works end-to-end: a project opted into
     * `git_branch` gets its commit on that branch regardless of what the working copy happened to
     * be on beforehand. Process::fake()-based tests can only prove the right command string was
     * built, not that it does the right thing against a real repo with real branches.
     */
    public function test_a_configured_branch_is_checked_out_before_the_commit_lands(): void
    {
        [$project, $repoPath] = $this->projectWithRealRepo(['git_branch' => 'main']);
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->initGitRepo($repoPath);
        $this->createBranch($repoPath, 'other');

        $response = $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project);
        $response->assertStatus(201);

        $branch = trim(Process::path($repoPath)->run('git rev-parse --abbrev-ref HEAD')->output());
        $this->assertSame('main', $branch);

        $log = Process::path($repoPath)->run('git log --oneline -1');
        $this->assertStringContainsString('Webdav Change: foo.xml', $log->output());
    }

    /**
     * A `git_branch` pointing at a branch that doesn't exist in the repo is a configuration error --
     * it must fail loudly (a logged error) and never produce a commit, rather than silently creating
     * a new divergent branch (which `checkout -B` would do) or committing to whatever was checked out.
     */
    public function test_a_git_branch_that_does_not_exist_fails_loudly_without_committing(): void
    {
        [$project, $repoPath] = $this->projectWithRealRepo(['git_branch' => 'does-not-exist']);
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->initGitRepo($repoPath);

        // Log::spy() isn't usable here: WebDavController's real HTTP path calls Log::getLogger() to
        // hand sabre its logger, and a spy returns null for that (breaking an unrelated part of the
        // request with a TypeError). The logged-error assertion is already covered, against a faked
        // Process, by MakeGitCommitTest::test_a_failed_checkout_logs_an_error_and_never_commits --
        // this real-repo test only needs to prove no commit happened.
        $response = $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project);
        $response->assertStatus(201);

        $log = Process::path($repoPath)->run('git log --oneline');
        $this->assertStringNotContainsString('Webdav Change', $log->output(), 'expected no commit to have been created');
    }

    /**
     * Sabre emits afterMove before the source's afterUnbind: the move has to land as one commit
     * holding both sides, with the afterUnbind that follows finding nothing left to commit.
     */
    public function test_a_real_webdav_move_produces_one_commit_with_both_sides(): void
    {
        [$project, $repoPath] = $this->projectWithRealRepo();
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->initGitRepo($repoPath);
        $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project)->assertStatus(201);

        $response = $this->webdav('MOVE', '/foo.xml', [
            'Destination' => "/connection/{$project->slug}/bar.xml",
        ], null, $user, $project);
        $response->assertStatus(201);

        $log = Process::path($repoPath)->run('git log --format=%s -1');
        $this->assertSame('Webdav Change: foo.xml -> bar.xml', trim($log->output()));
        $files = Process::path($repoPath)->run('git show --name-status --format= HEAD');
        $this->assertSame("R100\tdata/foo.xml\tdata/bar.xml", trim($files->output()));
        $this->assertSame('', trim(Process::path($repoPath)->run('git status --porcelain')->output()));
    }

    /**
     * The commit sent to the ediarum services must be the real one the change landed in -- only a
     * real repo proves MakeGitCommit's rev-parse runs after its commit and before PushDataChanges.
     */
    public function test_a_real_webdav_write_is_pushed_to_ediarum_services_with_its_commit(): void
    {
        config([
            'services.ediarum_services.url' => 'https://services.example.com',
            'services.ediarum_services.api_key' => 'services-key',
        ]);
        Http::fake(['*' => Http::response('', 200)]);
        [$project, $repoPath] = $this->projectWithRealRepo(['push_to_ediarum_services' => true]);
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->initGitRepo($repoPath);
        $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project)->assertStatus(201);

        $head = trim(Process::path($repoPath)->run('git rev-parse HEAD')->output());
        Http::assertSent(fn ($request) => $request->url() === 'https://services.example.com/files'
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'commit' && $part['contents'] === $head));
    }

    /**
     * A project folder without its own `.git` must not let git walk up into an enclosing repo. That
     * happened in CI: the test projects' folders sit inside the checkout, MakeGitCommit's
     * `git checkout main` switched the checkout itself to a stale local `main`, and the rest of the
     * run failed on files that branch didn't have.
     */
    public function test_a_project_folder_without_git_never_touches_an_enclosing_repo(): void
    {
        $outer = storage_path('framework/testing/disks/outer repo ' . uniqid());
        $this->tempDirs[] = $outer;
        $this->tempDirs[] = $this->project->repository_path;
        File::ensureDirectoryExists($outer);
        $this->initGitRepo($outer);
        $this->createBranch($outer, 'other');
        Process::path($outer)->run('git checkout other')->throw();
        File::put($outer . '/marker', 'only on other');
        Process::path($outer)->run('git add marker && git commit -m "marker"')->throw();

        config(['app.repositories_directory' => $outer . '/repos']);
        $project = Project::factory()->create([
            'git_branch' => 'main',
            'git_remote_url' => null,
            'git_username' => null,
            'git_access_token' => null,
        ]);
        $user = User::factory()->create();
        $project->users()->attach($user);

        $this->webdav('PUT', '/foo.xml', [], '<root/>', $user, $project)->assertStatus(201);

        $this->assertSame('other', trim(Process::path($outer)->run('git rev-parse --abbrev-ref HEAD')->output()));
        $this->assertFileExists($outer . '/marker');
        $this->assertSame('marker', trim(Process::path($outer)->run('git log --format=%s -1')->output()));
        $this->assertNotNull($project->fresh()->git_commit_broken_at);
    }

    /**
     * Points a fresh project at a real, uniquely-named repo root on disk -- `repository_path` is
     * `{repositories_directory}/{slug}` (see planning/init-project.md Part A), so a real repo path is
     * produced by pointing `repositories_directory` at this repo's parent and using its basename as
     * the slug, rather than a stored `data_folder_location` column. Registers cleanup of both this
     * repo and whatever WebDavTestCase::setUp() created for $this->project under the
     * repositories_directory this replaces, since that path is no longer reachable via config once
     * this method returns.
     *
     * @return array{0: Project, 1: string} [$project, $repoRootPath]
     */
    private function projectWithRealRepo(array $attributes = []): array
    {
        $staleProjectDir = $this->project->repository_path;
        $this->tempDirs[] = $staleProjectDir;

        $repoPath = storage_path('framework/testing/disks/git repo ' . uniqid());
        $this->tempDirs[] = $repoPath;
        config(['app.repositories_directory' => dirname($repoPath)]);

        // No Git remote credentials -- these tests are about the local git commit only, and the
        // factory's default fake credentials would otherwise make MakeGitCommit dispatch a real
        // (synchronous, since QUEUE_CONNECTION=sync in testing) PushToGitRemote against an unreachable
        // fake host once a commit lands.
        $project = Project::factory()->create(array_merge([
            'slug' => basename($repoPath),
            'git_remote_url' => null,
            'git_username' => null,
            'git_access_token' => null,
        ], $attributes));

        return [$project, $repoPath];
    }

    private function initGitRepo(string $path): void
    {
        // Explicit `-b main` rather than relying on `init.defaultBranch` -- the branch-aware tests
        // below check out `main` by name, and that must not depend on the test runner's git config.
        Process::path($path)->run('git init -b main')->throw();
        Process::path($path)->run('git config user.email "test@example.com"')->throw();
        Process::path($path)->run('git config user.name "Test"')->throw();
        Process::path($path)->run('git commit --allow-empty -m "initial commit"')->throw();
    }

    private function createBranch(string $path, string $branch): void
    {
        Process::path($path)->run("git branch {$branch}")->throw();
    }
}
