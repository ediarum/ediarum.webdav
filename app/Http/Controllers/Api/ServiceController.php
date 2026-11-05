<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Service side of the auth contract beyond `verify` (ediarum.platform docs/auth-contract.md), behind
 * the `auth.service` token. Not about a user: per-user views filter by verify's X-User-Projects.
 */
class ServiceController extends Controller
{
    /**
     * The project registry: every project, sorted by slug. Fields are mapped one by one so that new
     * columns, and the credentials stored next to these, never end up here by accident.
     */
    public function projects(): JsonResponse
    {
        return response()->json(
            Project::query()->orderBy('slug')->get()
                ->map(fn (Project $p) => [
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'repo_url' => self::withoutCredentials($p->git_remote_url),
                    'branch' => $p->git_branch,
                    'index_source_of_truth' => $p->is_index_app_source_of_truth ? 'app' : 'xml',
                ])
                ->values()
        );
    }

    /** `https://user:token@host/…` → `https://host/…`; credentials belong in git_username/PAT. */
    private static function withoutCredentials(?string $url): ?string
    {
        return $url === null ? null : preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', $url);
    }
}
