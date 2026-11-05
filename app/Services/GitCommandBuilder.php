<?php

namespace App\Services;

/**
 * Every command that runs inside a project's repo sets GIT_CEILING_DIRECTORIES to the repo's parent
 * directory, so git only ever uses the repo's own `.git`. Without it, a project folder that isn't a
 * git repo (deleted `.git`, half-finished setup) makes git walk up and act on whatever repo encloses
 * it -- `checkout` there switches someone else's working tree. A missing `.git` now fails with "not a
 * git repository" instead.
 */
class GitCommandBuilder
{
    public static function commit(string $repo, string $gitPath, string $userName, string $userEmail, string $message): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s add . && %s -c %s -c %s commit -m %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($gitPath),
            escapeshellarg("user.name={$userName}"),
            escapeshellarg("user.email={$userEmail}"),
            escapeshellarg($message),
        );
    }

    /**
     * Deliberately no `cd` (unlike every other method here) -- the destination doesn't exist yet, so
     * there's nothing to `cd` into before running it.
     */
    public static function clone(string $destination, string $gitPath, string $url): string
    {
        return sprintf(
            '%s clone %s %s',
            escapeshellarg($gitPath),
            escapeshellarg($url),
            escapeshellarg($destination),
        );
    }

    /**
     * Deliberately not `checkout -B`, which would create the branch if it doesn't already exist in
     * the repo -- a missing branch is a configuration error that should fail loudly, not silently
     * create a new divergent branch that then gets treated as if it were the real one.
     */
    public static function checkout(string $repo, string $gitPath, string $branch): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s checkout %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($branch),
        );
    }

    public static function authenticatedUrl(string $url, string $username, string $token): string
    {
        $auth = rawurlencode($username) . ':' . rawurlencode($token) . '@';

        return str_replace('https://', "https://{$auth}", $url);
    }

    /**
     * Explicit `branch:branch` refspec -- never the bare `push <url>` form, which relied on
     * whatever `push.default` resolved to for the currently checked-out branch.
     */
    public static function push(string $repo, string $gitPath, string $url, string $username, string $token, string $branch): string
    {
        $authenticatedUrl = self::authenticatedUrl($url, $username, $token);

        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s push %s %s:%s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($authenticatedUrl),
            escapeshellarg($branch),
            escapeshellarg($branch),
        );
    }

    /**
     * Lists remote branch names without fetching any objects -- cheap, read-only, doesn't touch the
     * local repo. Output is raw `<sha>\trefs/heads/<name>` lines; parsing is left to the caller.
     */
    public static function lsRemoteHeads(string $repo, string $gitPath, string $url): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s ls-remote --heads %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($url),
        );
    }

    /**
     * Fetches a single remote branch into a local scratch ref, without touching the working tree or
     * any `refs/remotes/origin/*` ref (there's no real configured remote to imply). The refspec is
     * force-updating (`+`) because the scratch ref is meant to be overwritten on every check -- a
     * later check for a branch that isn't a descendant of whatever the ref last pointed to would
     * otherwise be rejected by git as a non-fast-forward update of the ref itself.
     */
    public static function fetch(string $repo, string $gitPath, string $url, string $branch, string $intoRef): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s fetch %s %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($url),
            escapeshellarg("+{$branch}:{$intoRef}"),
        );
    }

    public static function revParse(string $repo, string $gitPath, string $ref): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s rev-parse %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($ref),
        );
    }

    /**
     * Exit code 0 means $ref exists; nonzero (with no output either way) means it doesn't. Prefer
     * this over inferring existence from a failed `rev-parse` -- rev-parse's "ambiguous argument"
     * error is meant for humans at a terminal, not for a caller that needs a clean yes/no.
     */
    public static function refExists(string $repo, string $gitPath, string $ref): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s show-ref --verify --quiet %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($ref),
        );
    }

    /**
     * Exit code 0 means $ancestor is an ancestor of (or equal to) $descendant. Callers should check
     * the process exit code, not stdout -- this command prints nothing on success.
     */
    public static function isAncestor(string $repo, string $gitPath, string $ancestor, string $descendant): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s merge-base --is-ancestor %s %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($ancestor),
            escapeshellarg($descendant),
        );
    }

    public static function revListCount(string $repo, string $gitPath, string $from, string $to): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s rev-list --count %s..%s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($from),
            escapeshellarg($to),
        );
    }

    public static function diffNameStatus(string $repo, string $gitPath, string $from, string $to): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s diff --name-status %s %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($from),
            escapeshellarg($to),
        );
    }

    public static function mergeFastForwardOnly(string $repo, string $gitPath, string $ref): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s merge --ff-only %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($ref),
        );
    }

    /**
     * `update-ref -d` rather than a plain delete so it's a no-op (exit 0) if the ref never got
     * created -- cleanup callers run this unconditionally, including on early-abort paths.
     */
    public static function deleteRef(string $repo, string $gitPath, string $ref): string
    {
        return sprintf(
            'cd %s && export GIT_CEILING_DIRECTORIES=%s && %s update-ref -d %s',
            escapeshellarg($repo),
            escapeshellarg(dirname($repo)),
            escapeshellarg($gitPath),
            escapeshellarg($ref),
        );
    }
}
