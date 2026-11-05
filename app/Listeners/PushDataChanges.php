<?php

namespace App\Listeners;

use App\Events\DataChange;
use App\Jobs\PushToEdiarumServices;
use App\Jobs\PushToExistDb;

class PushDataChanges
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     */
    public function handle(DataChange $event): void
    {
        $project = $event->project;

        if (PushToEdiarumServices::isEnabledFor($project)) {
            PushToEdiarumServices::dispatch(
                $event->project,
                $event->webDavEvent,
                $event->sourcePath,
                $event->destinationPath,
                $event->commit,
            )->onQueue('ediarum-services');
        }
        if ($project->exist_base_url
            && $project->exist_data_path
            && $project->exist_username
//            && $project->exist_password
        ) {
            PushToExistDb::dispatch(
                $event->project,
                $event->webDavEvent,
                $event->sourcePath,
                $event->destinationPath,
            )->onQueue('exist-db');
        }

    }
}
