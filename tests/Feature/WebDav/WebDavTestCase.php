<?php

namespace Tests\Feature\WebDav;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

abstract class WebDavTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->user);

        File::ensureDirectoryExists($this->project->data_folder_location);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project->data_folder_location);

        parent::tearDown();
    }

    /**
     * Make a raw WebDAV request against $this->project, authenticated as $this->user (or $as).
     *
     * Basic-auth guards short-circuit re-authentication once a request has been authenticated
     * (SessionGuard::basic() returns early when check() is already true), and the auth guard
     * instance is shared across every $this->call() within one test method -- so without
     * forgetGuards() here, a second webdav() call in the same test silently keeps using whichever
     * user authenticated first, regardless of the PHP_AUTH_USER/PW passed this time.
     */
    protected function webdav(string $method, string $path = '/', array $headers = [], ?string $body = null, ?User $as = null, ?Project $inProject = null)
    {
        $as ??= $this->user;
        $project = $inProject ?? $this->project;

        if ($this->app['auth']->guard()->check()) {
            $this->app['auth']->guard()->logout();
        }
        $this->app['auth']->forgetGuards();

        $server = $this->transformHeadersToServerVars($headers);
        $server['PHP_AUTH_USER'] = $as->email;
        $server['PHP_AUTH_PW'] = 'password';

        return $this->call($method, "/connection/{$project->slug}{$path}", [], [], [], $server, $body);
    }

    protected function fullPath(string $relativePath): string
    {
        return rtrim($this->project->data_folder_location, '/') . '/' . ltrim($relativePath, '/');
    }
}
