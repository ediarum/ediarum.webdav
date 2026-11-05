<?php

namespace Tests\Feature\WebDav;

use App\Models\User;
use Illuminate\Support\Facades\File;

class WebDavAuthorizationTest extends WebDavTestCase
{
    public function test_user_not_attached_to_project_is_forbidden(): void
    {
        $outsider = User::factory()->create();

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '0'], null, $outsider);

        $response->assertStatus(403);
    }

    public function test_admin_user_not_attached_to_project_still_succeeds(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '0'], null, $admin);

        $response->assertStatus(207);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $server = $this->transformHeadersToServerVars(['Depth' => '0']);

        $response = $this->call('PROPFIND', "/connection/{$this->project->slug}/", [], [], [], $server);

        $response->assertStatus(401);
    }

    public function test_unknown_project_slug_returns_404(): void
    {
        $response = $this->webdav('GET', '/', [], null, $this->user, new \App\Models\Project(['slug' => 'does-not-exist']));

        $response->assertStatus(404);
    }

    public function test_maintenance_mode_blocks_all_webdav_verbs(): void
    {
        $this->project->is_in_maintenance_mode = true;
        $this->project->save();

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '0']);

        $response->assertStatus(503);
        $this->assertStringContainsString($this->project->name, $response->getContent());
    }

    public function test_missing_data_folder_returns_503_instead_of_crashing(): void
    {
        File::deleteDirectory($this->project->data_folder_location);

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '0']);

        $response->assertStatus(503);
        $this->assertStringContainsString($this->project->name, $response->getContent());
        // The server path stays in the log; WebDAV clients don't learn where repositories live.
        $this->assertStringNotContainsString($this->project->repository_path, $response->getContent());
        $this->assertStringContainsString('not been initialized', $response->getContent());
    }
}
