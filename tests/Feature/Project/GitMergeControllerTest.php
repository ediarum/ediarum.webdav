<?php

namespace Tests\Feature\Project;

use App\Jobs\PushToEdiarumServices;
use App\Jobs\PushToExistDb;
use App\Jobs\PushToGitRemote;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GitMergeControllerTest extends TestCase
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

    public function test_a_non_admin_cannot_list_branches(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = $this->mergeableProject();

        $response = $this->actingAs($user)
            ->get(route('projects.git-branches', ['projectId' => $project->id]));

        $response->assertForbidden();
    }

    public function test_status_classifies_a_fast_forwardable_branch(): void
    {
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');

        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject($remote, $local);

        $response = $this->actingAs($admin)
            ->get(route('projects.git-branches.status', ['projectId' => $project->id, 'branch' => 'feature']));

        $response->assertOk();
        $response->assertJson(['status' => 'ff', 'commitCount' => 1]);
    }

    public function test_status_gives_a_clear_error_when_the_tracked_branch_does_not_exist_locally(): void
    {
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'foo.txt', 'hi', 'feature');

        $admin = User::factory()->create(['is_admin' => true]);
        config(['app.repositories_directory' => dirname($local)]);
        $project = Project::factory()->create([
            'slug' => basename($local),
            'git_branch' => 'does-not-exist',
            'git_remote_url' => $remote,
            'git_username' => 'user',
            'git_access_token' => 'token',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('projects.git-branches.status', ['projectId' => $project->id, 'branch' => 'feature']));

        $response->assertStatus(502);
        $error = $response->json('error');
        $this->assertStringContainsString("'does-not-exist' does not exist", $error);
        $this->assertStringNotContainsString('Exit Code', $error);
    }

    /**
     * The maintenance-mode guard must short-circuit before any git command runs at all -- not just
     * fail cleanly after touching the repo. Process::fake() + assertNothingRan() is the only way to
     * prove that, since a real-repo test could pass merely by leaving the repo unchanged despite
     * having still run (and thrown away) a fetch.
     */
    public function test_merge_refuses_and_touches_nothing_when_maintenance_mode_is_off(): void
    {
        Process::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject(is_in_maintenance_mode: false);

        $response = $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'feature']));

        $response->assertStatus(409);
        Process::assertNothingRan();
    }

    public function test_merge_refuses_when_active_locks_exist(): void
    {
        Process::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject(is_in_maintenance_mode: true);

        DB::table("locks_{$project->id}")->insert([
            'owner' => 'someone@example.com',
            'timeout' => 300,
            'created' => time(),
            'token' => 'opaquelocktoken:test-token',
            'scope' => 1,
            'depth' => 0,
            'uri' => 'foo.xml',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'feature']));

        $response->assertStatus(409);
        Process::assertNothingRan();
    }

    public function test_merge_fast_forwards_dispatches_the_sync_pipeline_and_leaves_maintenance_mode_on(): void
    {
        Bus::fake();
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'data/foo.txt', 'hi', 'feature');
        $featureSha = trim(Process::path($remote)->run('git rev-parse feature')->output());

        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject($remote, $local, is_in_maintenance_mode: true, existConfigured: true);
        $beforeSha = trim(Process::path($local)->run('git rev-parse main')->output());

        $response = $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'feature']));

        $response->assertOk();
        $response->assertJson(['before' => $beforeSha, 'after' => $featureSha, 'changedFiles' => 1]);

        $localMainSha = trim(Process::path($local)->run('git rev-parse main')->output());
        $this->assertSame($featureSha, $localMainSha);

        Bus::assertDispatched(PushToExistDb::class);
        Bus::assertDispatched(PushToGitRemote::class, fn ($job) => $job->queue === 'git-remote');

        $this->assertTrue((bool) $project->fresh()->is_in_maintenance_mode);
    }

    /**
     * git reports merged paths relative to the repository root; the sync pipeline, like WebDAV,
     * works relative to the data folder. Files outside it are not pushed anywhere.
     */
    public function test_merge_pushes_data_files_relative_to_the_data_folder_and_skips_everything_else(): void
    {
        Bus::fake();
        $this->enableEdiarumServices();
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'data/letters/foo.xml', '<foo/>', 'feature');
        $this->commitFile($remote, 'README.md', 'readme');

        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject($remote, $local, is_in_maintenance_mode: true, existConfigured: true, servicesEnabled: true);

        $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'feature']))
            ->assertOk()
            ->assertJson(['changedFiles' => 2]);

        Bus::assertDispatchedTimes(PushToExistDb::class, 1);
        Bus::assertDispatched(PushToExistDb::class, fn ($job) => $job->webDavEvent === 'afterCreateFile'
            && $job->sourcePath === 'letters/foo.xml');
        Bus::assertDispatchedTimes(PushToEdiarumServices::class, 1);
        Bus::assertDispatched(PushToEdiarumServices::class, fn ($job) => $job->webDavEvent === 'afterCreateFile'
            && $job->sourcePath === 'letters/foo.xml');
    }

    public function test_a_merged_rename_across_the_data_folder_edge_is_a_delete_or_a_create(): void
    {
        Bus::fake();
        $this->enableEdiarumServices();
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'data/leaving.xml', '<leaving/>');
        $this->commitFile($remote, 'drafts/arriving.xml', '<arriving/>');
        Process::path($local)->run('git pull -q origin main')->throw();

        Process::path($remote)->run('git checkout -q -b feature')->throw();
        File::ensureDirectoryExists($remote . '/archive');
        Process::path($remote)->run('git mv data/leaving.xml archive/leaving.xml')->throw();
        Process::path($remote)->run('git mv drafts/arriving.xml data/arriving.xml')->throw();
        Process::path($remote)->run('git commit -q -m "move across the data folder edge"')->throw();

        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject($remote, $local, is_in_maintenance_mode: true, existConfigured: true, servicesEnabled: true);

        $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'feature']))
            ->assertOk();

        foreach ([PushToExistDb::class, PushToEdiarumServices::class] as $job) {
            Bus::assertDispatchedTimes($job, 2);
            Bus::assertDispatched($job, fn ($j) => $j->webDavEvent === 'afterUnbind'
                && $j->sourcePath === 'leaving.xml' && $j->destinationPath === null);
            Bus::assertDispatched($job, fn ($j) => $j->webDavEvent === 'afterCreateFile'
                && $j->sourcePath === 'arriving.xml' && $j->destinationPath === null);
        }
    }

    /** The global half of PushToEdiarumServices::isEnabledFor(); the project's switch is mergeableProject()'s $servicesEnabled. */
    private function enableEdiarumServices(): void
    {
        config([
            'services.ediarum_services.url' => 'https://services.example.com',
            'services.ediarum_services.api_key' => 'services-key',
        ]);
    }

    public function test_merge_of_a_diverged_branch_leaves_the_repo_untouched_and_reports_the_rebase_message(): void
    {
        Bus::fake();
        [$remote, $local] = $this->remoteAndLocal();
        $this->commitFile($remote, 'remote-only.txt', 'x', 'other');
        $this->commitFile($local, 'local-only.txt', 'y');

        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->mergeableProject($remote, $local, is_in_maintenance_mode: true);
        $beforeSha = trim(Process::path($local)->run('git rev-parse main')->output());

        $response = $this->actingAs($admin)
            ->post(route('projects.git-branches.merge', ['projectId' => $project->id, 'branch' => 'other']));

        $response->assertStatus(409);
        $response->assertJsonFragment(['error' => "'other' has diverged from 'main'. Rebase it onto 'main' before adding its commits."]);

        $afterSha = trim(Process::path($local)->run('git rev-parse main')->output());
        $this->assertSame($beforeSha, $afterSha);

        Bus::assertNotDispatched(PushToGitRemote::class);
        $this->assertTrue((bool) $project->fresh()->is_in_maintenance_mode);
    }

    private function mergeableProject(?string $remote = null, ?string $local = null, bool $is_in_maintenance_mode = false, bool $existConfigured = false, bool $servicesEnabled = false): Project
    {
        $attributes = [
            'git_branch' => 'main',
            'is_in_maintenance_mode' => $is_in_maintenance_mode,
            'push_to_ediarum_services' => $servicesEnabled,
        ];

        if ($remote !== null) {
            config(['app.repositories_directory' => dirname($local)]);
            $attributes['slug'] = basename($local);
            $attributes['git_remote_url'] = $remote;
            $attributes['git_username'] = 'user';
            $attributes['git_access_token'] = 'token';
        }

        if ($existConfigured) {
            $attributes['exist_base_url'] = 'https://exist.example.com';
            $attributes['exist_data_path'] = '/db/data';
            $attributes['exist_username'] = 'admin';
        }

        return Project::factory()->create($attributes);
    }

    /**
     * @return array{0: string, 1: string} [$remotePath, $localPath], both starting from a single
     * "base" commit on `main` -- same shape as GitMergeServiceTest's helper of the same name.
     */
    private function remoteAndLocal(): array
    {
        $remote = storage_path('framework/testing/disks/git-merge-controller-remote-' . uniqid());
        $local = storage_path('framework/testing/disks/git-merge-controller-local-' . uniqid());
        $this->tempDirs[] = $remote;
        $this->tempDirs[] = $local;

        File::ensureDirectoryExists($remote);
        Process::path($remote)->run('git init -q -b main')->throw();
        Process::path($remote)->run('git config user.email "test@example.com"')->throw();
        Process::path($remote)->run('git config user.name "Test"')->throw();
        Process::path($remote)->run('git commit --allow-empty -m base')->throw();

        Process::run('git clone -q ' . escapeshellarg($remote) . ' ' . escapeshellarg($local))->throw();
        Process::path($local)->run('git config user.email "test@example.com"')->throw();
        Process::path($local)->run('git config user.name "Test"')->throw();

        return [$remote, $local];
    }

    private function commitFile(string $repo, string $file, string $contents, ?string $branch = null): void
    {
        if ($branch !== null) {
            Process::path($repo)->run('git checkout -q -b ' . escapeshellarg($branch))->throw();
        }

        File::ensureDirectoryExists(dirname($repo . '/' . $file));
        file_put_contents($repo . '/' . $file, $contents);
        Process::path($repo)->run('git add ' . escapeshellarg($file))->throw();
        Process::path($repo)->run('git commit -q -m ' . escapeshellarg("add {$file}"))->throw();
    }
}
