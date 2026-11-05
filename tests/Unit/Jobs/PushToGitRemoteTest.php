<?php

namespace Tests\Unit\Jobs;

use App\Jobs\PushToGitRemote;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PushToGitRemoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_url_encoded_into_the_push_url(): void
    {
        Process::fake();
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user@example.com',
            'git_access_token' => 'a/token:with@special-chars',
        ]);

        (new PushToGitRemote($project))->handle();

        Process::assertRan(function ($process) {
            $command = $process->command;
            $this->assertStringContainsString(
                'https://user%40example.com:a%2Ftoken%3Awith%40special-chars@git.example.org/group/repo.git',
                $command
            );

            return true;
        });
    }

    public function test_a_process_level_failure_does_not_leak_the_token_into_the_thrown_message(): void
    {
        // Simulates what a real timeout looks like: GitCommandBuilder::push() embeds the token in
        // the remote URL, and Symfony Process's own timeout exception message would contain the
        // full command line verbatim. That message is what would otherwise end up stored in
        // failed_jobs.exception and rendered as-is on the admin panel -- PushToGitRemote::runPush()
        // must never let it through unfiltered.
        Process::fake(fn () => new \RuntimeException(
            "The process \"'git' push 'https://user:leaked-token-xyz@git.example.org/group/repo.git' 'main':'main'\" exceeded the timeout of 10 seconds."
        ));
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'leaked-token-xyz',
            'git_branch' => 'main',
        ]);

        try {
            (new PushToGitRemote($project))->handle();
            $this->fail('Expected an exception.');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('leaked-token-xyz', $e->getMessage());
        }
    }

    public function test_a_configured_branch_is_pushed_with_an_explicit_refspec(): void
    {
        Process::fake();
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_branch' => 'main',
        ]);

        (new PushToGitRemote($project))->handle();

        Process::assertRan(fn ($process) => str_ends_with($process->command, "'main':'main'"));
    }

    public function test_failed_push_throws(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'push rejected', exitCode: 1));
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Push to Git remote failed');

        (new PushToGitRemote($project))->handle();
    }

    public function test_a_non_authentication_failure_is_not_retried_even_with_a_master_user_configured(): void
    {
        $this->withMasterCredentials('masteruser', 'mastertoken');

        Process::fake(fn () => Process::result(
            errorOutput: "! [rejected] main -> main (non-fast-forward)",
            exitCode: 1,
        ));
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('non-fast-forward');

        try {
            (new PushToGitRemote($project))->handle();
        } finally {
            Process::assertRanTimes(fn () => true, 1);
        }
    }

    public function test_an_authentication_failure_without_a_master_user_configured_throws_immediately(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'remote: HTTP Basic: Access denied', exitCode: 1));
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP Basic: Access denied');

        try {
            (new PushToGitRemote($project))->handle();
        } finally {
            Process::assertRanTimes(fn () => true, 1);
        }
    }

    public function test_an_authentication_failure_retries_with_the_master_user_and_succeeds(): void
    {
        $this->withMasterCredentials('masteruser', 'mastertoken');

        Process::fake([
            '*user%40example.com*' => Process::result(errorOutput: 'remote: HTTP Basic: Access denied', exitCode: 1),
            '*masteruser*' => Process::result(exitCode: 0),
        ]);
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user@example.com',
            'git_access_token' => 'token',
        ]);

        (new PushToGitRemote($project))->handle();

        Process::assertRanTimes(fn () => true, 2);
        Process::assertRan(fn ($process) => str_contains($process->command, 'masteruser'));

        $this->assertNotNull($project->fresh()->git_credentials_broken_at);
    }

    public function test_an_authentication_failure_that_also_fails_with_the_master_user_throws_with_both_errors(): void
    {
        $this->withMasterCredentials('masteruser', 'mastertoken');

        Process::fake([
            '*user%40example.com*' => Process::result(errorOutput: 'remote: HTTP Basic: Access denied', exitCode: 1),
            '*masteruser*' => Process::result(errorOutput: 'remote: HTTP Basic: Access denied', exitCode: 1),
        ]);
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user@example.com',
            'git_access_token' => 'token',
        ]);

        try {
            (new PushToGitRemote($project))->handle();
            $this->fail('Expected an exception to be thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('master Git user', $e->getMessage());
        }

        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_a_successful_push_with_the_projects_own_credentials_clears_a_previously_set_broken_flag(): void
    {
        Process::fake();
        $project = Project::factory()->create([
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_credentials_broken_at' => now()->subDay(),
        ]);

        (new PushToGitRemote($project))->handle();

        $this->assertNull($project->fresh()->git_credentials_broken_at);
    }

    /**
     * Sets config directly rather than putenv()+env(): PushToGitRemote reads these via config()
     * (config/services.php), not env() directly -- config() is always a live read with no cleanup
     * needed (each test gets a fresh config repository), unlike env(), which stopped reflecting
     * putenv() calls made after the application had already booted and read .env once (confirmed by
     * reproducing this exact suite under the CI image, php:8.3-cli -- not guessed): once a key exists
     * in .env, even as an empty string (as GIT_MASTER_USERNAME= does in .env.example), Laravel's
     * env() helper no longer picks up a later putenv() for that same key, even though raw getenv()
     * does. That silently made every test using putenv() here pass locally against a developer's own
     * .env (which may not define these keys at all) while failing in CI, where .env.example --
     * defining them blank -- is what's copied to .env.
     */
    public function test_middleware_serializes_pushes_per_project_and_does_not_drop_an_overlapping_one(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $middlewareA = (new PushToGitRemote($projectA))->middleware();
        $middlewareB = (new PushToGitRemote($projectB))->middleware();

        $this->assertCount(1, $middlewareA);
        $this->assertInstanceOf(WithoutOverlapping::class, $middlewareA[0]);

        // Keyed by project so two different projects' pushes never contend with each other.
        $this->assertNotSame($middlewareA[0]->getLockKey(new PushToGitRemote($projectA)), $middlewareB[0]->getLockKey(new PushToGitRemote($projectB)));

        // An overlapping attempt must be requeued, not silently dropped -- it may cover a commit
        // made after the running push already started.
        $this->assertNotNull($middlewareA[0]->releaseAfter);
    }

    private function withMasterCredentials(string $username, string $token): void
    {
        config([
            'services.git_remote.master_username' => $username,
            'services.git_remote.master_access_token' => $token,
        ]);
    }
}
