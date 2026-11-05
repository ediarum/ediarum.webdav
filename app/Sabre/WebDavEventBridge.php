<?php

namespace App\Sabre;

use App\Events\DataChange;
use App\Models\Project;

class WebDavEventBridge
{
    /**
     * $extra carries whatever sabre passed as the second emit() argument for this event, which
     * varies by event: the destination path (string) for afterMove, but a DAV\INode for
     * afterCreateFile/afterWriteContent (the parent/file node) -- only afterMove's is meaningful here.
     */
    public static function forward(
        string $webDavEvent,
        Project $project,
        string $userEmail,
        string $path,
        mixed $extra = null,
    ): void {
        if ($webDavEvent === 'afterMove') {
            DataChange::dispatch($userEmail, $project, $webDavEvent, $path, $extra);
        } else {
            DataChange::dispatch($userEmail, $project, $webDavEvent, $path);
        }
    }
}
