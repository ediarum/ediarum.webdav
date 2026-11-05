<?php

namespace App\Listeners;

use App\Events\DataChange;
use App\Jobs\PushToGitRemote;
use App\Models\Project;
use App\Models\ProjectCommit;
use App\Services\GitCommandBuilder;
use App\Services\GitCommitFailureClassifier;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class MakeGitCommit
{
    /**
     * A repo-local git operation (checkout, commit) should never legitimately take anywhere near
     * this long. This bound exists purely to turn a hung/stuck git process into a fast, logged
     * failure instead of hanging the WebDAV request that triggered it indefinitely.
     */
    private const COMMAND_TIMEOUT_SECONDS = 15;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(DataChange $event): void
    {
        $project = $event->project;
        $dataRepo = $project->repository_path;
        $gitUser = config('app.git_user_name');
        $gitEmail = config('app.git_user_email');
        $gitPath = env('GIT_PATH', 'git');

        try {
            $checkout = $this->runGitCommand($dataRepo, GitCommandBuilder::checkout($dataRepo, $gitPath, $project->git_branch));
        } catch (ProcessTimedOutException $e) {
            $this->handleTimeout($project, $e);

            return;
        }

        if ($checkout->failed()) {
            $message = "Git checkout of branch '{$project->git_branch}' failed for project {$project->name}. Error Output: '"
                . $checkout->errorOutput()
                . "'.Standard output: {$checkout->output()}";
            Log::error($message);
            $this->markCommitsBroken($project, $message);

            return;
        }

        // Sabre emits afterMove before the afterUnbind of the source, so the move is committed here,
        // in one commit with both sides, and the afterUnbind that follows finds nothing to commit.
        $commitMessage = $event->webDavEvent === 'afterMove'
            ? "Webdav Change: {$event->sourcePath} -> {$event->destinationPath}"
            : "Webdav Change: {$event->sourcePath}";
        $command = GitCommandBuilder::commit($dataRepo, $gitPath, $gitUser, $gitEmail, $commitMessage);

        try {
            $result = $this->runGitCommand($dataRepo, $command);
        } catch (ProcessTimedOutException $e) {
            $this->handleTimeout($project, $e);

            return;
        }

        if ($result->successful()) {
            $this->markCommitsHealthy($project);
            $event->commit = $this->head($dataRepo, $gitPath);

            try {
                ProjectCommit::create([
                    'project_id' => $project->id,
                    'committed_at' => now(),
                    'commit_hash' => $event->commit,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to record commit statistics for project {$project->name}: " . $e->getMessage());
            }

            if ($project->git_remote_url
                && $project->git_username
                && $project->git_access_token
            ) {
                PushToGitRemote::dispatch($project)->onQueue('git-remote');
            }

            return;
        }

        $message = "Git command failed for project {$project->name}. Error Output: '"
            . $result->errorOutput()
            . "'.Standard output: {$result->output()}";
        Log::error($message);

        // "Nothing to commit" (e.g. a WebDAV write of identical content) is a routine, frequent, and
        // entirely benign outcome, not evidence that anything is broken -- see
        // GitCommitFailureClassifier::isNothingToCommit().
        if (GitCommitFailureClassifier::isNothingToCommit($result->output() . $result->errorOutput())) {
            // The change is already in HEAD (identical content, or the source side of a move).
            $event->commit = $this->head($dataRepo, $gitPath);
        } else {
            $this->markCommitsBroken($project, $message);
        }
    }

    /**
     * The commit that now holds the event's change, for PushDataChanges to pass on. Null if it
     * can't be determined -- the push then simply goes out without one.
     */
    private function head(string $repo, string $gitPath): ?string
    {
        try {
            $result = Process::timeout(self::COMMAND_TIMEOUT_SECONDS)->run(GitCommandBuilder::revParse($repo, $gitPath, 'HEAD'));
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $result->successful() && trim($result->output()) !== '' ? trim($result->output()) : null;
    }

    /**
     * Runs a git command and, if it fails because of a stale lock file left behind by a previous
     * process (killed mid-operation, or hung and reaped), clears that specific lock and retries
     * exactly once -- mirroring PushToGitRemote's one-retry-only shape for a different failure class.
     * Any other failure, or a failure that persists after the retry, is returned as-is for the caller
     * to handle. See GitCommitFailureClassifier::staleLockPath() for why this is safe to do
     * automatically.
     */
    private function runGitCommand(string $repo, string $command): ProcessResult
    {
        $result = Process::timeout(self::COMMAND_TIMEOUT_SECONDS)->run($command);

        if (!$result->failed()) {
            return $result;
        }

        $lockPath = GitCommitFailureClassifier::staleLockPath($result->output() . $result->errorOutput());

        // Sanity check before deleting anything: only ever remove a path git itself named, and only
        // if it's actually inside that repo's .git directory.
        if ($lockPath === null || !str_contains($lockPath, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
            return $result;
        }

        Log::warning("Detected a stale git lock file '{$lockPath}' in repo '{$repo}' -- removing it and retrying once.");
        @unlink($lockPath);

        return Process::timeout(self::COMMAND_TIMEOUT_SECONDS)->run($command);
    }

    /**
     * A genuine hang (as opposed to a stale lock, which fails fast) is not retried -- retrying
     * immediately into the same contended resource is likely to just hang again. It's surfaced
     * exactly like any other broken-commits failure instead.
     */
    private function handleTimeout(Project $project, ProcessTimedOutException $e): void
    {
        $message = "Git command timed out after " . self::COMMAND_TIMEOUT_SECONDS . " seconds for project {$project->name}: " . $e->getMessage();
        Log::error($message);
        $this->markCommitsBroken($project, $message);
    }

    private function markCommitsBroken(Project $project, string $reason): void
    {
        $project->git_commit_broken_at ??= now();
        $project->git_commit_broken_reason = Str::limit($reason, 2000);
        $project->save();
    }

    private function markCommitsHealthy(Project $project): void
    {
        if ($project->git_commit_broken_at === null) {
            return;
        }

        $project->git_commit_broken_at = null;
        $project->git_commit_broken_reason = null;
        $project->save();
    }
}
