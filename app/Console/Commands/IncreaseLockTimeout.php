<?php

namespace App\Console\Commands;

use App\Models\Lock;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IncreaseLockTimeout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:increase-lock-timeout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $projects = Project::get();
        foreach ($projects as $project)
        {
            $table = 'locks_' . $project->id;

            if (!\Schema::hasTable($table)) {
                $this->error("Table {$table} does not exist. Skipping project ID: {$project->id}");
                continue;
            }

            DB::table($table)
                ->where('timeout', 1800)
                ->update(['timeout' => 1000000000]);
        }

    }
}
