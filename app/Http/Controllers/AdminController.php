<?php

namespace App\Http\Controllers;

use App\Models\FailedJob;
use App\Models\Project;
use App\Models\ProjectCommit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(config('app.admin_panel_enabled'), 404);

        // ?hours= lets an admin widen/narrow the window without a deploy; 24h is a broadly useful
        // default landing view (covers a full day including outside working hours), narrower than
        // that is a bookmarkable query string away (e.g. ?hours=5).
        $hours = max(1, (int) $request->query('hours', 24));

        $recentCommits = ProjectCommit::with('project')
            ->where('committed_at', '>=', now()->subHours($hours))
            ->orderByDesc('committed_at')
            ->limit(200)
            ->get()
            ->map(fn (ProjectCommit $c) => [
                'project' => $this->projectRef($c->project),
                'committedAt' => $c->committed_at->toDateTimeString(),
                'committedAgo' => $c->committed_at->diffForHumans(),
                'commitHash' => $c->commit_hash,
                'commitUrl' => $c->commit_hash ? $c->project->remoteCommitUrl($c->commit_hash) : null,
            ]);

        $failedGitRemotePushes = FailedJob::where('queue', 'git-remote')
            ->latest('failed_at')
            ->limit(50)
            ->get()
            ->map(function (FailedJob $job) {
                $payload = json_decode($job->payload);
                $command = unserialize($payload->data->command);

                return [
                    'project' => $this->projectRef($command->project),
                    'failedAt' => (string) $job->failed_at,
                    'reason' => $job->reason(),
                ];
            });

        $projectsUsingMasterFallback = Project::whereNotNull('git_credentials_broken_at')
            ->orderBy('git_credentials_broken_at')
            ->get()
            ->map(fn (Project $p) => [
                'project' => $this->projectRef($p),
                'since' => $p->git_credentials_broken_at->diffForHumans(),
            ]);

        $projectsWithBrokenCommits = Project::whereNotNull('git_commit_broken_at')
            ->orderBy('git_commit_broken_at')
            ->get()
            ->map(fn (Project $p) => [
                'project' => $this->projectRef($p),
                'since' => $p->git_commit_broken_at->diffForHumans(),
                'reason' => Str::limit((string) $p->git_commit_broken_reason, 200),
            ]);

        return response()->json([
            'commitHours' => $hours,
            'recentCommits' => $recentCommits->values(),
            'failedGitRemotePushes' => $failedGitRemotePushes->values(),
            'projectsUsingMasterFallback' => $projectsUsingMasterFallback->values(),
            'projectsWithBrokenCommits' => $projectsWithBrokenCommits->values(),
        ]);
    }

    private function projectRef(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name];
    }
}
