<?php

namespace App\Models;

use App\Jobs\PushToExistDb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class FailedJob extends Model
{
    use HasFactory;

    private ?PushToExistDb $decodedCommand = null;

    /**
     * A short, human-readable summary of why the job failed. `exception` holds the full
     * `(string) $e` dump the queue worker stored -- PHP's default Exception::__toString() format is
     * "ExceptionClass: message in /absolute/file/path.php:123\nStack trace:\n#0 ...". This strips the
     * class-name prefix and file:line suffix off the first line, leaving just the message. Falls back
     * to the raw first line if the format doesn't match (a custom __toString(), for instance).
     */
    public function reason(): string
    {
        $firstLine = explode("\n", $this->exception, 2)[0];

        $withoutClassPrefix = preg_replace('/^[A-Za-z0-9_\\\\]+: /', '', $firstLine, 1) ?? $firstLine;

        return preg_replace('/ in \/.*:\d+$/', '', $withoutClassPrefix, 1) ?? $withoutClassPrefix;
    }

    /**
     * The eXist-db push job that failed, decoded from `payload->data->command`. Cached on the
     * instance since callers (retry, correlation) tend to call this more than once per row.
     */
    public function command(): PushToExistDb
    {
        return $this->decodedCommand ??= unserialize(json_decode($this->payload)->data->command);
    }

    public function uuid(): string
    {
        return json_decode($this->payload)->uuid;
    }

    /**
     * The `LIKE` clause is a cheap pre-filter -- the serialized Project model embeds `i:<id>;`
     * (PHP's serialize() format for an integer property) right after its `id` key; filtering by the
     * decoded command's project id afterwards is the real, correct check, so a `LIKE` match alone
     * colliding with an unrelated integer elsewhere in the payload (there isn't one, in practice)
     * would just cost a wasted unserialize, not a correctness bug. Deliberately not `"id";i:<id>;`
     * (closer to the key name, and what an earlier version of this query used): `payload` is a
     * JSON-encoded string wrapping that serialized PHP string, so its literal quotes come back
     * backslash-escaped in the stored text -- and MySQL's `LIKE` treats `\` as its own
     * pattern-escape character, silently consuming that backslash and failing to match (confirmed
     * against a real row in the dev MySQL database, not guessed) even though the equivalent
     * `whereRaw('LOCATE(?, payload) > 0', ...)` this replaced worked fine there, since `LOCATE()`
     * does a plain substring search with no escape semantics. SQLite's `LIKE` has no default escape
     * character at all, so a backslash-bearing needle passed the test suite (`phpunit.xml` runs
     * tests against `sqlite`/`:memory:`) while actually broken on `mysql` (production) -- this
     * driver mismatch is exactly why that version needed replacing. Sticking to a backslash- and
     * quote-free needle sidesteps LIKE's escape handling entirely, so this one works identically on
     * both.
     */
    private static function queryForProject(int $projectId): Builder
    {
        return static::where('queue', 'exist-db')
            ->where('payload', 'like', '%i:' . $projectId . ';%');
    }

    /**
     * The 50 most recent failed eXist-db pushes belonging to $projectId, newest first -- capped
     * purely for display: there's no indexed project_id/path column on failed_jobs to query more
     * cheaply, and a project's failure list has no reason to show more than a page of rows. Do not
     * reuse this for anything that needs the *complete* set for a project (e.g. deciding what to
     * delete after a successful retry) -- see allForProjectAndPath().
     */
    public static function forProject(int $projectId): Collection
    {
        return static::queryForProject($projectId)
            ->latest('failed_at')
            ->limit(50)
            ->get()
            ->filter(fn (self $job) => $job->command()->project->id === $projectId)
            ->values();
    }

    /**
     * Every failed eXist-db push for $projectId and $sourcePath, uncapped. A file can accumulate
     * more failures than forProject()'s 50-row display cap keeps around; retrying it must still
     * find and clear every one of them, not just whichever happened to fall inside that window --
     * otherwise older rows resurface on the next page load once the newer duplicates are deleted.
     */
    public static function allForProjectAndPath(int $projectId, string $sourcePath): Collection
    {
        return static::queryForProject($projectId)
            ->get()
            ->filter(fn (self $job) => $job->command()->project->id === $projectId
                && $job->command()->sourcePath === $sourcePath)
            ->values();
    }
}
