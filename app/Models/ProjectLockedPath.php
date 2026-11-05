<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectLockedPath extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'path',
        'locked_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The locked-path row that covers $path (itself, or an ancestor directory), if any. Loads every
     * locked path for the project rather than pushing the prefix match into SQL -- this list is
     * small and admin-curated (unlike the WebDAV editing-locks table, which can churn constantly),
     * so doing the comparison in PHP avoids a LIKE pattern built from a column value.
     */
    public static function coveringPath(int $projectId, string $path): ?self
    {
        return static::where('project_id', $projectId)->get()
            ->first(fn (self $locked) => $path === $locked->path || str_starts_with($path, $locked->path . '/'));
    }
}
