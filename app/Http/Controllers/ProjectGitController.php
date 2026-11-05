<?php

namespace App\Http\Controllers;

use App\Events\DataChange;
use App\Jobs\PushToGitRemote;
use App\Listeners\PushDataChanges;
use App\Models\Project;
use App\Services\GitMergeService;
use App\Services\LockTableManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ProjectGitController extends Controller
{
    public function branches(Request $request, int $projectId)
    {
        $project = $this->mergeableProject($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        try {
            $branches = (new GitMergeService())->listBranches($project, $request->query('q'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['branches' => $branches]);
    }

    public function status(int $projectId, string $branch)
    {
        $project = $this->mergeableProject($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        try {
            $classification = (new GitMergeService())->classify($project, $branch);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($classification);
    }

    public function merge(Request $request, int $projectId, string $branch)
    {
        $project = $this->mergeableProject($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        if (!$project->is_in_maintenance_mode) {
            return response()->json(['error' => 'Enable maintenance mode before adding commits.'], 409);
        }

        if (LockTableManager::hasActiveLocks($project->id)) {
            return response()->json(['error' => 'Cannot add commits while there are active locks. Please release all locks first.'], 409);
        }

        try {
            $result = (new GitMergeService())->merge($project, $branch);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $this->dispatchDataChanges($project, $request->user()->email, $result['changes'], $result['after']);

        if ($project->git_remote_url && $project->git_username && $project->git_access_token) {
            PushToGitRemote::dispatch($project)->onQueue('git-remote');
        }

        return response()->json([
            'before' => $result['before'],
            'after' => $result['after'],
            'changedFiles' => count($result['changes']),
        ]);
    }

    private function mergeableProject(int $projectId): Project
    {
        Gate::authorize('manage-users');

        return Project::findOrFail($projectId);
    }

    /**
     * Dispatches each merged file straight into PushDataChanges (the eXist-db / ediarum-services sync
     * pipeline), deliberately bypassing the DataChange event bus. Firing the real event would also
     * invoke MakeGitCommit, which would try (and fail, noisily) to `git commit` changes that were
     * already brought in by the fast-forward -- the git side of this is handled explicitly above via
     * PushToGitRemote, not by re-running the WebDAV commit listener.
     *
     * git reports paths relative to the repository root, while a DataChange (like every WebDAV
     * event) is relative to the data folder. Changes outside the data folder (README, CI config, …)
     * are not edition data and are skipped; a rename across the data folder's edge is reported as
     * the create or delete it amounts to inside it.
     */
    private function dispatchDataChanges(Project $project, string $user, array $changes, string $commit): void
    {
        $listener = new PushDataChanges();

        foreach ($changes as $change) {
            $webDavEvent = match ($change['status']) {
                'A' => 'afterCreateFile',
                'M' => 'afterWriteContent',
                'D' => 'afterUnbind',
                'R' => 'afterMove',
                default => null,
            };

            if ($webDavEvent === null) {
                continue;
            }

            $path = Project::toDataPath($change['path']);
            $previousPath = $change['previousPath'] === null ? null : Project::toDataPath($change['previousPath']);

            if ($webDavEvent === 'afterMove' && ($path === null || $previousPath === null)) {
                [$webDavEvent, $path] = $path !== null ? ['afterCreateFile', $path] : ['afterUnbind', $previousPath];
            }

            if ($path === null) {
                continue;
            }

            $listener->handle(new DataChange(
                $user,
                $project,
                $webDavEvent,
                $webDavEvent === 'afterMove' ? $previousPath : $path,
                $webDavEvent === 'afterMove' ? $path : null,
                $commit,
            ));
        }
    }
}
