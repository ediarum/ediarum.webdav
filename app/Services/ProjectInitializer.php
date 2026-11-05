<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Clones a project's repo from its Git remote into its server-managed directory, checks out its tracked
 * branch, and verifies it has a top-level `data` folder -- the three things that must all succeed
 * before the rest of the app (WebDAV, git commits/merges, eXist-db/ediarum-services sync) can be used
 * for that project. See planning/init-project.md Part C.
 */
class ProjectInitializer
{
    /**
     * @param  ?callable(string): void  $onProgress  Called once per step, before that step's own
     *     outcome is known -- lets a caller (the SSE stream in ProjectController::initializeStream())
     *     relay progress to a live terminal-style UI instead of only learning the final outcome.
     *
     * @throws RuntimeException on any failure. The project's directory is removed before throwing in
     *     every failure case except a missing REPOSITORIES_DIRECTORY config (nothing was created yet).
     */
    public function initialize(Project $project, ?callable $onProgress = null): void
    {
        $report = $onProgress ?? function (string $message) {};
        $repo = $project->repository_path;
        $gitPath = env('GIT_PATH', 'git');

        if (!config('app.repositories_directory')) {
            throw new RuntimeException('REPOSITORIES_DIRECTORY is not configured.');
        }

        // Server-managed directory -- nothing but this service ever writes here, so a from-scratch
        // clone on every (re)attempt is safe and is what makes a retry after a failed attempt just
        // work, instead of merging onto whatever a half-finished previous attempt left behind.
        if (File::isDirectory($repo)) {
            File::deleteDirectory($repo);
        }

        $report("Cloning {$project->git_remote_url} …");
        $clone = Process::timeout(60)->run(
            GitCommandBuilder::clone($repo, $gitPath, $this->authenticatedUrl($project))
        );
        if ($clone->failed()) {
            throw new RuntimeException('Could not clone the repository: ' . $clone->errorOutput());
        }
        $report('Repository cloned.');

        $report("Checking out branch '{$project->git_branch}' …");
        $checkout = Process::timeout(15)->run(
            GitCommandBuilder::checkout($repo, $gitPath, $project->git_branch)
        );
        if ($checkout->failed()) {
            File::deleteDirectory($repo);
            throw new RuntimeException(
                "Branch '{$project->git_branch}' does not exist in this repository."
            );
        }
        $report('Branch checked out.');

        $dataFolder = Project::DATA_FOLDER;
        $report("Verifying the repository has a top-level '{$dataFolder}' folder …");
        if (!File::isDirectory($repo . '/' . $dataFolder)) {
            File::deleteDirectory($repo);
            throw new RuntimeException(
                "This repository does not contain a top-level '{$dataFolder}' folder."
            );
        }

        $project->initialized_at = now();
        $project->save();
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
