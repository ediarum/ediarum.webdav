<?php

namespace App\Services;

use App\Models\FailedJob;
use App\Models\Project;

class FailedPushRetryService
{
    /**
     * Replays the most recently failed eXist-db push for $sourcePath and, only on success, deletes
     * every failed_jobs row for that same file (a file can accumulate several failures, e.g. a
     * failed write followed by a failed move -- one successful retry clears all of them). Uses
     * FailedJob::allForProjectAndPath(), not forProject(), specifically because this decides what
     * to delete -- forProject()'s 50-row display cap must never leave stale rows behind.
     */
    public function retryFile(Project $project, string $sourcePath): array
    {
        $matching = FailedJob::allForProjectAndPath($project->id, $sourcePath);

        if ($matching->isEmpty()) {
            return ['success' => false, 'error' => 'No matching failed push found.'];
        }

        $latest = $matching->sortByDesc('failed_at')->first();

        try {
            $latest->command()->handle();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        FailedJob::whereIn('id', $matching->pluck('id'))->delete();

        return ['success' => true, 'error' => null];
    }

    /**
     * Retries every distinct failed file for $project (deduped by sourcePath), best-effort -- one
     * file failing does not stop the run. $output is called once per file with (sourcePath, success,
     * error) so a caller can stream progress.
     */
    public function retryAllForProject(Project $project, callable $output): void
    {
        $files = FailedJob::forProject($project->id)
            ->map(fn (FailedJob $j) => $j->command()->sourcePath)
            ->unique()
            ->values();

        foreach ($files as $sourcePath) {
            $result = $this->retryFile($project, $sourcePath);
            $output($sourcePath, $result['success'], $result['error']);
        }
    }
}
