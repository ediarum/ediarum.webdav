<?php

namespace App\Http\Controllers;

use App\Helpers\ExistDbClient;
use App\Models\FailedJob;
use App\Models\Project;
use App\Services\ExistDBSyncService;
use App\Services\FailedPushRetryService;
use App\Services\LockTableManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ProjectServicesController extends Controller
{
    public function enableMaintenanceMode(Request $request, $projectId){
        if(LockTableManager::hasActiveLocks((int) $projectId)){
            return response()->json([
                'error' => 'Cannot enable maintenance mode while there are active locks. Please release all locks first.',
            ], 409);
        }
        $project = Project::findOrFail($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        $project->is_in_maintenance_mode = true;
        $project->save();

        return response()->json(['isInMaintenanceMode' => true]);
    }

    public function disableMaintenanceMode(Request $request, $projectId){
        $project = Project::findOrFail($projectId);
        $project->is_in_maintenance_mode = false;
        $project->save();

        return response()->json(['isInMaintenanceMode' => false]);
    }

    public function pushFilesToExistdb(Request $request, $projectId)
    {

        $folder= $request['folder'] ?? null;

        $project = Project::findOrFail($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        $service = new ExistDbSyncService(
            new ExistDbClient($project->exist_base_url, $project->exist_username, $project->exist_password),
            $project->data_folder_location,
            $project->exist_data_path
        );

        set_time_limit(0);
        ignore_user_abort(true);

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null) {
                echo "event: app-error\n";
                echo "data: [FATAL ERROR] {$error['message']} in {$error['file']}:{$error['line']}\n\n";
                @ob_flush();
                @flush();
            }
        });

        return response()->stream(function () use ($service, $folder) {
            try {

                $service->syncFolder($folder, function ($message) {
                    echo "data:  {$message}\n\n"; // SSE format
                    ob_flush();
                    flush();
                });
                echo "data: ExistDBSyncIsComplete! \n\n";
                ob_flush();
                flush();

                echo "data: [DONE]\n\n";
                ob_flush();
                flush();
            } catch (\Exception $e) {
                echo "event: app-error\n";
                echo "data: Error syncing project: {$e->getMessage()}\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection'   => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function retryFailedPush(Request $request, int $projectId, string $uuid)
    {
        // PushToExistDb bounds its own HTTP call to a 10s connect / 30s total timeout, but
        // without raising PHP's own limit here that request can still get killed by
        // max_execution_time first -- which ends the request with a bare 500 and no JSON body,
        // so the frontend has no error to show and falls back to a generic message.
        set_time_limit(45);

        $project = Project::findOrFail($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        $job = FailedJob::where('uuid', $uuid)->firstOrFail();

        abort_unless($job->command()->project->id === $project->id, 404);

        $result = app(FailedPushRetryService::class)->retryFile($project, $job->command()->sourcePath);

        return response()->json($result);
    }

    public function retryAllFailedPushes(Request $request, int $projectId)
    {
        $project = Project::findOrFail($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        return response()->stream(function () use ($project) {
            try {
                app(FailedPushRetryService::class)->retryAllForProject($project, function ($file, $success, $error) {
                    echo 'data: ' . json_encode(['file' => $file, 'success' => $success, 'error' => $error]) . "\n\n";
                    ob_flush();
                    flush();
                });
                echo "data: [DONE]\n\n";
                ob_flush();
                flush();
            } catch (\Throwable $e) {
                // Something broke outside the per-file loop (e.g. the project vanished mid-run) -- this
                // is the only case that ends the stream early; a single file failing is not this.
                echo "event: app-error\n";
                echo "data: {$e->getMessage()}\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
