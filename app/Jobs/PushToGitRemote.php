<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\GitCommandBuilder;
use App\Services\GitPushFailureClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PushToGitRemote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Project $project
    )
    {
    }

    /**
     * Two pushes for the same project running concurrently race each other on the remote's ref
     * lock -- each `git push` targets the same branch, and whichever loses the compare-and-swap
     * update fails with "cannot lock ref ... is at X but expected Y", even though there's no real
     * content conflict (the local branch is a clean fast-forward either way). Serializing per
     * project removes the race instead of just retrying past it.
     *
     * An overlapping attempt is released back onto the queue (not dropped) -- it may cover a commit
     * made after the running push already started, so simply discarding it could leave that commit
     * unpushed until some later write happens to trigger another push. expireAfter is a crash-only
     * safety net -- comfortably longer than the push timeout below, so it never fires in the normal
     * case where the lock is released in `finally` -- so a worker that dies mid-push doesn't wedge
     * this project's pushes forever.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->project->id))
                ->releaseAfter(5)
                ->expireAfter(config('services.git_remote.push_timeout', 60) + 60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $dataRepo = $this->project->repository_path;
        $gitPath = env('GIT_PATH', 'git');

        $result = $this->runPush($dataRepo, $gitPath, $this->project->git_username, $this->project->git_access_token);

        if ($result->successful()) {
            if ($this->project->git_credentials_broken_at !== null) {
                $this->project->git_credentials_broken_at = null;
                $this->project->save();
            }

            return;
        }

        if (!GitPushFailureClassifier::isAuthenticationFailure($result->errorOutput())) {
            Log::error("Push failed:" . $result->errorOutput());
            throw new \Exception("Push to Git remote failed: " . $result->errorOutput());
        }

        $masterUsername = config('services.git_remote.master_username');
        $masterToken = config('services.git_remote.master_access_token');

        if (!$masterUsername || !$masterToken) {
            Log::error("Push failed (authentication) and no master Git user is configured: " . $result->errorOutput());
            throw new \Exception("Push to Git remote failed: " . $result->errorOutput());
        }

        Log::warning("Push to Git remote failed authentication for project {$this->project->name} using its own "
            . "credentials; retrying with the master Git user. Error output: " . $result->errorOutput());

        $retryResult = $this->runPush($dataRepo, $gitPath, $masterUsername, $masterToken);

        if ($retryResult->failed()) {
            Log::error("Push failed with the master Git user too, for project {$this->project->name}: " . $retryResult->errorOutput());
            throw new \Exception(
                "Push to Git remote failed even with the master Git user: " . $retryResult->errorOutput()
                . " (original error using the project's own credentials: " . $result->errorOutput() . ")"
            );
        }

        Log::warning("Push to Git remote for project {$this->project->name} succeeded using the master Git "
            . "user after the project's own credentials failed authentication -- its access token likely "
            . "needs to be renewed.");

        if ($this->project->git_credentials_broken_at === null) {
            $this->project->git_credentials_broken_at = now();
            $this->project->save();
        }
    }

    /**
     * GitCommandBuilder::push() embeds the access token directly in the remote URL (that's how git
     * authenticates an HTTPS push), and a process-level failure -- a timeout, above all --
     * propagates as an exception whose message is Symfony Process's own "The process "<full
     * command line>" ..." text, token included verbatim. Left uncaught, that message becomes this
     * job's failed_jobs.exception row, which FailedJob::reason() renders as-is on the admin panel:
     * anyone who can view failed pushes would see the token. The real detail still goes to the log
     * (not web-exposed); what gets thrown here never contains the command line.
     */
    private function runPush(string $dataRepo, string $gitPath, string $username, string $token): ProcessResult
    {
        try {
            return Process::timeout(config('services.git_remote.push_timeout', 60))->run(GitCommandBuilder::push(
                $dataRepo, $gitPath, $this->project->git_remote_url, $username, $token, $this->project->git_branch,
            ));
        } catch (\Throwable $e) {
            Log::error("git push process error for project {$this->project->name}: " . $e->getMessage());

            throw new \Exception("git push to the Git remote did not complete for project {$this->project->name} (see server log for detail).");
        }
    }
}
