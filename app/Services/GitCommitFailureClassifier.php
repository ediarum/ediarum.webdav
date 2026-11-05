<?php

namespace App\Services;

class GitCommitFailureClassifier
{
    /**
     * Git guards every write to its own on-disk state (the index, HEAD, a ref, ...) with a
     * "<path>.lock" file, and refuses to proceed if one is already there -- normally because another
     * git process is running concurrently, but just as often because a previous process (killed
     * mid-operation, or hung and reaped by the OS) left an orphaned one behind. This app is the only
     * thing that ever writes to these repos, so a lock file git says is blocking us can only be one
     * of our own orphans; left alone, it fails every future commit the same way, silently, forever --
     * which is the actual incident this class exists to stop from recurring.
     *
     * Returns the exact lock file path git reported (so the caller removes that specific file, not a
     * guessed-at "index.lock"), or null if this isn't a lock-file failure at all.
     */
    public static function staleLockPath(string $gitOutput): ?string
    {
        if (preg_match("/Unable to create '([^']+\.lock)': File exists/", $gitOutput, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * A `git commit` with nothing to commit (e.g. a WebDAV write of identical content) exits
     * non-zero, but is a routine, frequent, and entirely expected outcome -- not evidence of a
     * broken repo or a stuck git process. Callers must not treat this the same as a real failure, or
     * the "commits are broken" signal would fire constantly on healthy projects and get ignored,
     * exactly the failure mode it exists to prevent.
     */
    public static function isNothingToCommit(string $gitOutput): bool
    {
        return str_contains($gitOutput, 'nothing to commit');
    }
}
