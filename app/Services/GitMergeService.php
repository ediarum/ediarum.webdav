<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Git mechanics for the "merge from another branch" feature. Never does anything beyond a
 * `merge --ff-only` -- see GitMergeUiPlan (planning/git-merge-ui.md) for why: this feature exists
 * specifically to avoid ever creating a merge commit or touching history it isn't certain about.
 */
class GitMergeService
{
    /**
     * Deliberately not `refs/remotes/origin/*` -- there's no real configured remote for these repos
     * to imply, so a made-up namespace under the app's own name is used instead.
     */
    public const SCRATCH_REF = 'refs/ediarum/incoming';

    private string $gitPath;

    public function __construct(?string $gitPath = null)
    {
        $this->gitPath = $gitPath ?? env('GIT_PATH', 'git');
    }

    /**
     * @return string[] remote branch names, optionally substring-filtered by $query
     */
    public function listBranches(Project $project, ?string $query = null): array
    {
        $url = $this->authenticatedUrl($project);
        $repo = $project->repository_path;

        $result = $this->runAuthenticated(
            GitCommandBuilder::lsRemoteHeads($repo, $this->gitPath, $url), 15, 'list branches',
        );

        if ($result->failed()) {
            throw new RuntimeException('Could not list branches from the Git remote: ' . $result->errorOutput());
        }

        $branches = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [, $ref] = preg_split('/\s+/', $line, 2);
            $branches[] = preg_replace('#^refs/heads/#', '', $ref);
        }

        if ($query !== null && $query !== '') {
            $branches = array_values(array_filter($branches, fn ($b) => str_contains($b, $query)));
        }

        sort($branches);

        return $branches;
    }

    /**
     * Fetches $branch into the scratch ref and classifies its relationship to the project's tracked
     * branch per the three-way table in planning/git-merge-ui.md (B1): 'ff', 'up_to_date', or
     * 'diverged'. For 'ff', also includes a preview (commit count, changed files).
     */
    public function classify(Project $project, string $branch): array
    {
        $repo = $project->repository_path;
        $mainRef = 'refs/heads/' . $project->git_branch;

        if (Process::timeout(15)->run(GitCommandBuilder::refExists($repo, $this->gitPath, $mainRef))->failed()) {
            throw new RuntimeException(
                "The tracked branch '{$project->git_branch}' does not exist in this project's repository. "
                . "Check it out on the server, or fix the tracked branch in this project's settings."
            );
        }

        $this->fetchIntoScratchRef($project, $branch);

        $mainSha = $this->revParse($repo, $mainRef);
        $otherSha = $this->revParse($repo, self::SCRATCH_REF);

        $status = $this->classifyShas($repo, $mainSha, $otherSha);

        $result = [
            'status' => $status,
            'mainSha' => $mainSha,
            'otherSha' => $otherSha,
        ];

        if ($status === 'ff') {
            $result += $this->preview($repo, $mainSha, $otherSha);
        }

        return $result;
    }

    /**
     * Re-classifies (never trusting an earlier `status` call, since branches can move between
     * requests), then performs the fast-forward if it's still eligible. Returns the before/after SHAs
     * and the changed-file list so the caller can feed them into the WebDAV event pipeline.
     *
     * @throws RuntimeException if the branch is no longer a clean fast-forward, or the merge fails.
     */
    public function merge(Project $project, string $branch): array
    {
        $repo = $project->repository_path;

        $classification = $this->classify($project, $branch);

        if ($classification['status'] !== 'ff') {
            $this->deleteScratchRef($project);

            throw new RuntimeException(match ($classification['status']) {
                'up_to_date' => "'{$project->git_branch}' is already up to date with '{$branch}'.",
                default => "'{$branch}' has diverged from '{$project->git_branch}'. Rebase it onto '{$project->git_branch}' before adding its commits.",
            });
        }

        $before = $classification['mainSha'];

        $checkout = Process::timeout(15)->run(GitCommandBuilder::checkout($repo, $this->gitPath, $project->git_branch));
        if ($checkout->failed()) {
            $this->deleteScratchRef($project);
            throw new RuntimeException("Could not check out '{$project->git_branch}': " . $checkout->errorOutput());
        }

        $mergeResult = Process::timeout(15)->run(GitCommandBuilder::mergeFastForwardOnly($repo, $this->gitPath, self::SCRATCH_REF));
        $this->deleteScratchRef($project);

        if ($mergeResult->failed()) {
            throw new RuntimeException('Could not add those commits (fast-forward failed): ' . $mergeResult->errorOutput());
        }

        $after = $this->revParse($repo, 'refs/heads/' . $project->git_branch);

        return [
            'before' => $before,
            'after' => $after,
            'changes' => $this->changedPaths($repo, $before, $after),
        ];
    }

    private function fetchIntoScratchRef(Project $project, string $branch): void
    {
        $repo = $project->repository_path;
        $url = $this->authenticatedUrl($project);

        $result = $this->runAuthenticated(
            GitCommandBuilder::fetch($repo, $this->gitPath, $url, $branch, self::SCRATCH_REF), 30, 'fetch branch',
        );

        if ($result->failed()) {
            throw new RuntimeException("Could not fetch branch '{$branch}': " . $result->errorOutput());
        }
    }

    /**
     * $command embeds the access token directly in the remote URL (that's how these read-only git
     * operations authenticate over HTTPS). A process-level failure -- a timeout, above all --
     * propagates as an exception whose message is Symfony Process's own "The process "<full
     * command line>" ..." text, token included verbatim. ProjectGitController's endpoints catch
     * RuntimeException and return its message straight to the frontend, so letting that through
     * unfiltered would leak the token to any user of the merge feature; the real detail still goes
     * to the log, which isn't web-exposed.
     */
    private function runAuthenticated(string $command, int $timeout, string $action): ProcessResult
    {
        try {
            return Process::timeout($timeout)->run($command);
        } catch (\Throwable $e) {
            Log::error("git {$action} process error: " . $e->getMessage());

            throw new RuntimeException("Could not {$action} from the Git remote (see server log for detail).");
        }
    }

    private function classifyShas(string $repo, string $mainSha, string $otherSha): string
    {
        if ($mainSha === $otherSha) {
            return 'up_to_date';
        }

        if (Process::timeout(15)->run(GitCommandBuilder::isAncestor($repo, $this->gitPath, $otherSha, $mainSha))->successful()) {
            return 'up_to_date';
        }

        if (Process::timeout(15)->run(GitCommandBuilder::isAncestor($repo, $this->gitPath, $mainSha, $otherSha))->successful()) {
            return 'ff';
        }

        return 'diverged';
    }

    private function preview(string $repo, string $mainSha, string $otherSha): array
    {
        $count = Process::timeout(15)->run(GitCommandBuilder::revListCount($repo, $this->gitPath, $mainSha, $otherSha))->throw();

        return [
            'commitCount' => (int) trim($count->output()),
            'changedFiles' => $this->changedPaths($repo, $mainSha, $otherSha),
        ];
    }

    /**
     * @return array<int, array{status: string, path: string, previousPath: ?string}>
     */
    private function changedPaths(string $repo, string $from, string $to): array
    {
        $diff = Process::timeout(15)->run(GitCommandBuilder::diffNameStatus($repo, $this->gitPath, $from, $to))->throw();

        $changes = [];
        foreach (preg_split('/\r?\n/', trim($diff->output())) as $line) {
            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);
            $status = $columns[0];

            if (str_starts_with($status, 'R')) {
                $changes[] = ['status' => 'R', 'path' => $columns[2], 'previousPath' => $columns[1]];
            } else {
                $changes[] = ['status' => $status, 'path' => $columns[1], 'previousPath' => null];
            }
        }

        return $changes;
    }

    private function revParse(string $repo, string $ref): string
    {
        return trim(Process::timeout(15)->run(GitCommandBuilder::revParse($repo, $this->gitPath, $ref))->throw()->output());
    }

    private function deleteScratchRef(Project $project): void
    {
        Process::timeout(15)->run(GitCommandBuilder::deleteRef($project->repository_path, $this->gitPath, self::SCRATCH_REF));
    }

    private function authenticatedUrl(Project $project): string
    {
        return GitCommandBuilder::authenticatedUrl(
            $project->git_remote_url,
            $project->git_username,
            $project->git_access_token,
        );
    }
}
