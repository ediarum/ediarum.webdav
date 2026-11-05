<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LockTableManager
{
    public static function tableNameFor(int $projectId): string
    {
        return 'locks_' . $projectId;
    }

    public static function hasActiveLocks(int $projectId): bool
    {
        return DB::table(self::tableNameFor($projectId))->count() > 0;
    }

    /**
     * Whether any WebDAV editing lock exists at $path itself or anywhere under it. Mirrors
     * Sabre\DAV\Locks\Backend\PDO::getLocks()'s own "uri = ? OR uri LIKE '?/%'" shape (see
     * vendor/sabre/dav/lib/DAV/Locks/Backend/PDO.php) -- just without its additional "check every
     * ancestor directory too" branch, since that exists to find locks that *cover* a queried file
     * (the opposite direction of what's needed here: this finds locks *inside* a path about to be
     * locked, not locks on its ancestors).
     */
    public static function hasActiveLocksUnderPath(int $projectId, string $path): bool
    {
        return DB::table(self::tableNameFor($projectId))
            ->where(fn ($q) => $q->where('uri', $path)->orWhere('uri', 'like', $path . '/%'))
            ->exists();
    }

    public static function ensureTableForProject(int $projectId): void
    {
        $table = self::tableNameFor($projectId);

        if (!Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table) {
                $table->id();
                $table->string("owner", 200);
                $table->unsignedInteger("timeout");
                $table->integer("created");
                $table->string("token", 200)->index();
                $table->tinyInteger('scope');
                $table->tinyInteger('depth');
                $table->string('uri')->index();
            });
        }
    }

    public static function dropTableForProject(int $projectId): void
    {
        $table = self::tableNameFor($projectId);

        if (Schema::hasTable($table)) {
            Schema::dropIfExists($table);
        }
    }

}
