<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

class ProjectUserController extends Controller
{
    public function attach(Request $request)
    {
        $project = Project::findOrFail($request->project_id);
        $project->users()->syncWithoutDetaching([$request->user_id]);

        return response()->json(['success' => true]);
    }

    public function detach(Request $request)
    {
        $project = Project::findOrFail($request->project_id);
        $project->users()->detach($request->user_id);

        return response()->json(['success' => true]);
    }
}
