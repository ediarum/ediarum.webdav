<?php

namespace Tests\Unit\App\Models;

use App\Models\Project;
use App\Models\ProjectLockedPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLockedPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exact_path_match_is_covered(): void
    {
        $project = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $project->id, 'path' => 'folder/file.xml']);

        $covering = ProjectLockedPath::coveringPath($project->id, 'folder/file.xml');

        $this->assertNotNull($covering);
        $this->assertSame('folder/file.xml', $covering->path);
    }

    public function test_a_file_under_a_locked_directory_is_covered(): void
    {
        $project = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $project->id, 'path' => 'folder']);

        $this->assertNotNull(ProjectLockedPath::coveringPath($project->id, 'folder/file.xml'));
    }

    public function test_a_file_two_levels_under_a_locked_directory_is_covered(): void
    {
        $project = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $project->id, 'path' => 'folder']);

        $this->assertNotNull(ProjectLockedPath::coveringPath($project->id, 'folder/sub/file.xml'));
    }

    public function test_an_unrelated_sibling_path_is_not_covered(): void
    {
        $project = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $project->id, 'path' => 'folder']);

        $this->assertNull(ProjectLockedPath::coveringPath($project->id, 'other-folder/file.xml'));
    }

    public function test_a_string_prefix_that_is_not_a_real_ancestor_is_not_covered(): void
    {
        $project = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $project->id, 'path' => 'folder']);

        $this->assertNull(ProjectLockedPath::coveringPath($project->id, 'folder-other/file.xml'));
    }

    public function test_locks_do_not_leak_across_projects(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        ProjectLockedPath::factory()->create(['project_id' => $projectA->id, 'path' => 'folder']);

        $this->assertNull(ProjectLockedPath::coveringPath($projectB->id, 'folder/file.xml'));
    }
}
