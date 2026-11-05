<?php

namespace App\Http\Controllers;

use App\Helpers\FolderContents;
use App\Jobs\PushToEdiarumServices;
use App\Http\Requests\ProjectRequest;
use App\Models\FailedJob;
use App\Models\Project;
use App\Models\ProjectLockedPath;
use App\Models\User;
use App\Sabre\WebDavBaseUri;
use App\Services\LockTableManager;
use App\Services\ProjectInitializer;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /**
     * Sorted by name; the list view sorts client-side from here (A–Z, Z–A, last edited).
     * `lastEditedAt` is the latest WebDAV commit (project_commits), null if there never was one.
     */
    public function index()
    {
        return response()->json(
            Project::withMax('commits', 'committed_at')->orderBy('name')->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'initialized' => $p->initialized_at !== null,
                    'isInMaintenanceMode' => (bool) $p->is_in_maintenance_mode,
                    'lastEditedAt' => $p->commits_max_committed_at
                        ? Carbon::parse($p->commits_max_committed_at)->toIso8601String()
                        : null,
                ])
                ->values()
        );
    }

    /**
     * Store a newly created resource in storage. Deliberately does not also run
     * ProjectInitializer here -- the dialog that calls this (resources/spa/components/
     * InitializeProjectDialog.vue) opens the SSE stream (ProjectController::initializeStream())
     * itself once it has the new project's id, so the clone/checkout progress can be relayed live
     * rather than only learning the outcome after the fact.
     */
    public function store(ProjectRequest $request)
    {

        $validated = $request->validated();
        $project = new Project();
        $project->fill($validated);

        foreach($project->getHidden() as $h) {
            if (isset($validated[$h])) {
                $project->{$h} = $validated[$h];
            }
        }

        $project->save();

        LockTableManager::ensureTableForProject($project->id);

        return response()->json(['id' => $project->id], 201);
    }

    /**
     * Streams live progress from ProjectInitializer as Server-Sent Events -- same shape as
     * ProjectServicesController::pushFilesToExistdb(). Serves both "Initialize" (never attempted)
     * and "Retry Initialization" (previously failed): both are just "initialized_at is null, open
     * the stream again," and ProjectInitializer already wipes and re-clones from scratch every time.
     */
    public function initializeStream(int $id)
    {
        $project = Project::findOrFail($id);

        set_time_limit(0);
        ignore_user_abort(true);

        return response()->stream(function () use ($project) {
            try {
                (new ProjectInitializer())->initialize($project, function (string $message) {
                    echo "data: {$message}\n\n";
                    ob_flush();
                    flush();
                });
                echo "data: [DONE]\n\n";
                ob_flush();
                flush();
            } catch (\RuntimeException $e) {
                echo "event: app-error\n";
                echo "data: {$e->getMessage()}\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Everything the project page (resources/spa/views/ProjectShowView.vue) and its edit form
     * need, in one payload. Secrets are never sent back, only whether one is saved.
     */
    public function show(string $id)
    {
        $p = Project::with('users')->findOrFail($id);

        $users = User::whereDoesntHave('projects',
            function (Builder $q) use ($id) {
                $q->where('project_id', $id);
            })
            ->orderBy("name")
            ->get();

        $gitRemote = $p->git_remote_url && $p->git_username && $p->git_access_token;
        $exist = $p->exist_base_url && $p->exist_data_path && $p->exist_username && $p->exist_password;
        $locks =
            DB::table("locks_$id")
            ->orderBy('created')
            ->get()
            ->map(function ($l) {
                $lock['id'] = $l->id;
                $lock['created'] = Carbon::createFromTimestampUTC($l->created)->setTimezone(config('app.timezone'))->toDateTimeString();
                $lock['timeElapsed'] = CarbonInterval::seconds(time() - $l->created)->cascade()->forHumans();
                $lock['owner'] = $l->owner;
                $lock['file'] = $l->uri;
                return $lock;
            });

        $failedJobs = FailedJob::forProject((int) $p->id)
            ->map(fn (FailedJob $job) => [
                "uuid" => $job->uuid(),
                "file" => $job->command()->sourcePath,
                "time" => (string) $job->failed_at,
            ]);



        // Nothing below assumes a data folder exists on disk unless the project has actually been
        // initialized -- see planning/init-project.md Part D.
        $folders = [];
        $lockedPaths = collect();

        if ($p->initialized_at) {
            $fileSystem = new FolderContents();
            $fileSystem->parseFileSystemFolderContents(
                $p->data_folder_location
            );
            $folders = $fileSystem->getFolders()->toArray();

            $lockedPaths = $p->lockedPaths()->orderBy('path')->get()
                ->map(fn (ProjectLockedPath $lp) => [
                    'id' => $lp->id,
                    'path' => $lp->path,
                    'lockedBy' => $lp->locked_by,
                    'createdAt' => $lp->created_at->toDateTimeString(),
                ])->values();
        }

        return response()->json([
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'initialized' => $p->initialized_at !== null,
            'isInMaintenanceMode' => (bool) $p->is_in_maintenance_mode,
            'webdavUrl' => config('app.webdav_origin') . WebDavBaseUri::build(config('app.subpath'), $p->slug),
            'gitBranch' => $p->git_branch,
            'gitCommitBroken' => $p->git_commit_broken_at !== null,
            'gitCommitBrokenReason' => $p->git_commit_broken_at
                ? Str::limit((string) $p->git_commit_broken_reason, 200)
                : null,
            'gitRemoteConfigured' => (bool) $gitRemote,
            'gitRemoteUrl' => $p->git_remote_url,
            'ediarumServicesUrl' => PushToEdiarumServices::isEnabledFor($p) ? PushToEdiarumServices::url() : null,
            'existConfigured' => (bool) $exist,
            'existUrl' => $exist ? "{$p->exist_base_url}/rest{$p->exist_data_path}" : null,
            'settings' => [
                'name' => $p->name,
                'slug' => $p->slug,
                'git_remote_url' => $p->git_remote_url,
                'git_username' => $p->git_username,
                'git_branch' => $p->git_branch,
                'push_to_ediarum_services' => $p->push_to_ediarum_services,
                'is_index_app_source_of_truth' => $p->is_index_app_source_of_truth,
                'exist_base_url' => $p->exist_base_url,
                'exist_data_path' => $p->exist_data_path,
                'exist_username' => $p->exist_username,
                'has_git_access_token' => $p->git_access_token !== null,
                'has_exist_password' => $p->exist_password !== null,
            ],
            'folders' => $folders,
            'locks' => $locks->values(),
            'lockedPaths' => $lockedPaths->values(),
            'failedJobs' => $failedJobs->values(),
            'users' => $p->users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'availableUsers' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProjectRequest $request, string $id)
    {
        $validated = $request->validated();
        $project = Project::findOrFail($id);
        $project->fill($validated);

        foreach($project->getHidden() as $h){
            if(isset($validated[$h])){
                $project->{$h} = $validated[$h];
            }
            // git_access_token is mandatory bootstrap config, not an optional push
            // target, since Part B of planning/init-project.md -- there's no supported "configured
            // project with no Git remote credentials" state to delete into, so unlike the other secret
            // fields it can never be cleared this way.
            else if ($h !== 'git_access_token' && $request->boolean($h . "_delete")) {
                $project->{$h} = null;
            }
        }

        // Optimistically clear the "using the master fallback user" flag whenever the admin
        // actually changes either credential field -- otherwise it only clears on the *next
        // successful push* (see PushToGitRemote::handle()), so fixing a broken token here left the
        // admin panel and project page showing it as still broken until another WebDAV write
        // happened to trigger one. If the new credentials are still bad, the next push attempt
        // sets it again on its own.
        if ($project->isDirty(['git_username', 'git_access_token'])) {
            $project->git_credentials_broken_at = null;
        }

        $project->save();

        return response()->json(['id' => $project->id]);
    }

    public function removeLock(int $projectId, int $lockId)
    {
        DB::table("locks_$projectId")->where('id', $lockId)->delete();

        return response()->json(['success' => true]);
    }
}
