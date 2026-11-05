<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectCommit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Covers the create-a-project half of planning/init-project.md Part C: store() now responds with
 * JSON (for InitializeProjectDialog.vue's own axios call) instead of redirecting, slug uniqueness is
 * enforced before anything is ever cloned, and initializeStream() streams ProjectInitializer's
 * progress as SSE.
 */
class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_store_creates_the_project_and_responds_with_its_id_as_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->postJson(route('projects.store'), [
            'name' => 'A New Project',
            'slug' => 'a-new-project',
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_branch' => 'main',
        ]);

        $response->assertCreated();
        $project = Project::where('slug', 'a-new-project')->firstOrFail();
        $response->assertJson(['id' => $project->id]);
        $this->assertNull($project->initialized_at);
    }

    public function test_store_rejects_a_duplicate_slug_before_anything_is_cloned(): void
    {
        Process::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create(['slug' => 'taken-slug']);

        $response = $this->actingAs($admin)->postJson(route('projects.store'), [
            'name' => 'Another Project',
            'slug' => 'taken-slug',
            'git_remote_url' => 'https://git.example.org/group/repo.git',
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_branch' => 'main',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
        Process::assertNothingRan();
    }

    public function test_store_only_accepts_slugs_of_lowercase_letters_digits_and_single_inner_hyphens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $invalid = [
            'Upper', 'under_score', 'with space', 'comma,slug', 'umlaut-ä', "line\nbreak", '',
            '-', '---', '-leading', 'trailing-', 'double--hyphen',
        ];

        foreach ($invalid as $slug) {
            $this->actingAs($admin)->postJson(route('projects.store'), [
                'name' => 'Project ' . md5($slug),
                'slug' => $slug,
                'git_remote_url' => 'https://git.example.org/group/repo.git',
                'git_username' => 'user',
                'git_access_token' => 'token',
                'git_branch' => 'main',
            ])->assertStatus(422)->assertJsonValidationErrors('slug');
        }

        $this->assertSame(0, Project::count());
    }

    public function test_store_accepts_well_formed_slugs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach (['a', '2024', 'schleiermacher', 'humboldt-2', 'a-b-c'] as $slug) {
            $this->actingAs($admin)->postJson(route('projects.store'), [
                'name' => 'Project ' . $slug,
                'slug' => $slug,
                'git_remote_url' => 'https://git.example.org/group/repo.git',
                'git_username' => 'user',
                'git_access_token' => 'token',
                'git_branch' => 'main',
            ])->assertCreated();
        }
    }

    public function test_store_requires_git_remote_configuration(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->postJson(route('projects.store'), [
            'name' => 'Another Project',
            'slug' => 'another-project',
            'git_branch' => 'main',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['git_remote_url', 'git_username', 'git_access_token']);
    }

    public function test_a_non_admin_cannot_create_a_project(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->postJson(route('projects.store'), [
            'name' => 'Another Project',
            'slug' => 'another-project',
        ]);

        $response->assertForbidden();
    }

    public function test_initialize_stream_happy_path_clones_and_marks_initialized(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $remote = $this->remoteWithDataFolder();

        $repositoriesDirectory = storage_path('framework/testing/disks/init-stream-' . uniqid());
        $this->tempDirs[] = $repositoriesDirectory;
        config(['app.repositories_directory' => $repositoriesDirectory]);

        $project = Project::factory()->uninitialized()->create([
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_branch' => 'main',
        ]);

        $response = $this->actingAs($admin)->get(route('projects.initialize-stream', ['id' => $project->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('data: [DONE]', $content);
        $this->assertStringNotContainsString('event: app-error', $content);
        $this->assertNotNull($project->fresh()->initialized_at);
    }

    public function test_initialize_stream_reports_a_failure_and_leaves_initialized_at_null(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $remote = $this->remoteWithDataFolder();

        $repositoriesDirectory = storage_path('framework/testing/disks/init-stream-fail-' . uniqid());
        $this->tempDirs[] = $repositoriesDirectory;
        config(['app.repositories_directory' => $repositoriesDirectory]);

        $project = Project::factory()->uninitialized()->create([
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'token',
            'git_branch' => 'does-not-exist',
        ]);

        $response = $this->actingAs($admin)->get(route('projects.initialize-stream', ['id' => $project->id]));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('event: app-error', $content);
        $this->assertStringContainsString("'does-not-exist' does not exist", $content);
        $this->assertNull($project->fresh()->initialized_at);
        $this->assertFalse(File::isDirectory($project->repository_path));
    }

    public function test_uninitialized_project_gates_webdav_git_branches_and_locked_paths(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->uninitialized()->create();
        $project->users()->attach($admin);

        $this->actingAs($admin)
            ->get(route('projects.git-branches', ['projectId' => $project->id]))
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson(route('projects.lock-path', ['projectId' => $project->id]), ['path' => 'foo'])
            ->assertStatus(409);

        $server = ['PHP_AUTH_USER' => $admin->email, 'PHP_AUTH_PW' => 'password'];
        $this->call('PROPFIND', "/connection/{$project->slug}/", [], [], [], $server)
            ->assertStatus(503);
    }

    private function remoteWithDataFolder(): string
    {
        $remote = storage_path('framework/testing/disks/project-controller-remote-' . uniqid());
        $this->tempDirs[] = $remote;

        File::ensureDirectoryExists($remote);
        Process::path($remote)->run('git init -q -b main')->throw();
        Process::path($remote)->run('git config user.email "test@example.com"')->throw();
        Process::path($remote)->run('git config user.name "Test"')->throw();
        File::ensureDirectoryExists($remote . '/data');
        file_put_contents($remote . '/data/.gitkeep', '');
        Process::path($remote)->run('git add data')->throw();
        Process::path($remote)->run('git commit -q -m "add data folder"')->throw();

        return $remote;
    }

    public function test_index_lists_projects_by_name_with_their_last_webdav_edit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $zeta = Project::factory()->create(['name' => 'Zeta']);
        $alpha = Project::factory()->create(['name' => 'Alpha', 'initialized_at' => null]);
        ProjectCommit::factory()->create(['project_id' => $zeta->id, 'committed_at' => now()->subDays(3)]);
        ProjectCommit::factory()->create(['project_id' => $zeta->id, 'committed_at' => $latest = now()->subHour()->startOfSecond()]);

        $response = $this->actingAs($admin)->getJson(route('projects.index'));

        $response->assertOk();
        $response->assertJsonPath('0.id', $alpha->id);
        $response->assertJsonPath('0.initialized', false);
        $response->assertJsonPath('0.lastEditedAt', null);
        $response->assertJsonPath('1.id', $zeta->id);
        $response->assertJsonPath('1.initialized', true);
        $response->assertJsonPath('1.lastEditedAt', $latest->toIso8601String());
    }

    public function test_show_never_sends_secrets_back_only_whether_one_is_saved(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create([
            'initialized_at' => null,
            'git_access_token' => 'glpat-very-secret',
            'exist_password' => 'exist-very-secret',
        ]);

        $response = $this->actingAs($admin)->getJson(route('projects.show', $project));

        $response->assertOk();
        $response->assertJsonPath('id', $project->id);
        $response->assertJsonPath('settings.has_git_access_token', true);
        $response->assertJsonPath('settings.has_exist_password', true);
        $this->assertStringNotContainsString('glpat-very-secret', $response->getContent());
        $this->assertStringNotContainsString('exist-very-secret', $response->getContent());
    }

    public function test_show_gives_the_public_webdav_url_on_the_webdav_origin_not_the_app_url(): void
    {
        config([
            'app.url' => 'https://ediarum.example.org',
            'app.webdav_origin' => 'https://webdav.example.org',
            'app.subpath' => '/webdav',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['slug' => 'my-edition', 'initialized_at' => null]);

        $response = $this->actingAs($admin)->getJson(route('projects.show', $project));

        $response->assertJsonPath('webdavUrl', 'https://webdav.example.org/webdav/connection/my-edition');
    }

    public function test_show_does_not_reveal_where_the_repository_lives_on_the_server(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();

        $response = $this->actingAs($admin)->getJson(route('projects.show', $project));

        $response->assertOk();
        $response->assertJsonMissingPath('dataFolder');
        $this->assertStringNotContainsString(
            str_replace('/', '\\/', rtrim(config('app.repositories_directory'), '/')),
            $response->getContent()
        );
        $this->assertStringNotContainsString(rtrim(config('app.repositories_directory'), '/'), $response->getContent());
    }

    public function test_show_lists_project_users_and_the_users_that_can_still_be_added(): void
    {
        $admin = User::factory()->create(['name' => 'Admin', 'is_admin' => true]);
        $member = User::factory()->create(['name' => 'Member']);
        $project = Project::factory()->create(['initialized_at' => null]);
        $project->users()->attach($member);

        $response = $this->actingAs($admin)->getJson(route('projects.show', $project));

        $response->assertJsonPath('users', [['id' => $member->id, 'name' => 'Member']]);
        $response->assertJsonPath('availableUsers', [['id' => $admin->id, 'name' => 'Admin']]);
        $response->assertJsonPath('folders', []);
    }

    public function test_show_of_an_initialized_project_lists_its_folders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create();
        $this->tempDirs[] = $project->repository_path;
        File::ensureDirectoryExists($project->data_folder_location . '/letters');

        $response = $this->actingAs($admin)->getJson(route('projects.show', $project));

        $response->assertOk();
        $this->assertNotEmpty($response->json('folders'));
    }
}
