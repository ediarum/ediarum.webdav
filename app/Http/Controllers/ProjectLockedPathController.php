<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectLockedPath;
use App\Services\LockTableManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectLockedPathController extends Controller
{
    public function store(Request $request, int $projectId)
    {
        $project = Project::findOrFail($projectId);

        if (!$project->initialized_at) {
            return response()->json(['error' => 'This project has not been initialized yet.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first('path')], 422);
        }

        $path = trim($request->input('path'), '/');

        // Admin-supplied free text -- reject anything (e.g. `../`) that resolves outside the
        // project's own data folder, same defensive shape as every other path this app trusts an
        // admin to type rather than pick from a list.
        $realRoot = realpath($project->data_folder_location);
        $realTarget = realpath($project->data_folder_location . '/' . $path);

        if ($realRoot === false
            || $realTarget === false
            || !($realTarget === $realRoot || str_starts_with($realTarget, $realRoot . DIRECTORY_SEPARATOR))
        ) {
            return response()->json(['error' => "'{$path}' is not a valid path within this project."], 422);
        }

        if ($existing = ProjectLockedPath::coveringPath($projectId, $path)) {
            return response()->json(['error' => "'{$path}' is already covered by an existing lock on '{$existing->path}'."], 422);
        }

        if (LockTableManager::hasActiveLocksUnderPath($projectId, $path)) {
            return response()->json(['error' => "Cannot lock '{$path}' while there are active editing locks under it. Please release them first."], 422);
        }

        $lockedPath = ProjectLockedPath::create([
            'project_id' => $projectId,
            'path' => $path,
            'locked_by' => $request->user()->email,
        ]);

        return response()->json([
            'id' => $lockedPath->id,
            'path' => $lockedPath->path,
            'lockedBy' => $lockedPath->locked_by,
            'createdAt' => $lockedPath->created_at->toDateTimeString(),
        ]);
    }

    public function destroy(int $projectId, int $lockedPathId)
    {
        ProjectLockedPath::where('project_id', $projectId)->findOrFail($lockedPathId)->delete();

        return response()->json(['success' => true]);
    }
}
