<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\LockTableManager;
use Illuminate\Console\Command;

class InitiateLockTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:initiate-lock-tables';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup Lock Tables Scopes to Each Project';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $projects = Project::get();
        foreach ($projects as $project)
        {
            LockTableManager::ensureTableForProject($project->id);
        }

    }
}
