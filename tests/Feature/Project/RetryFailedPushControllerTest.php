<?php

namespace Tests\Feature\Project;

use App\Jobs\PushToExistDb;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetryFailedPushControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::factory()->create([
            'exist_base_url' => 'https://exist.example.com',
            'exist_data_path' => '/db/data',
            'exist_username' => 'admin',
            'exist_password' => 'secret',
        ]);
        File::ensureDirectoryExists($this->project->data_folder_location);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project->data_folder_location);

        parent::tearDown();
    }

    private function insertFailedExistDbPush(Project $project, string $sourcePath): string
    {
        $job = new PushToExistDb($project, 'afterCreateFile', $sourcePath);
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'exist-db',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => PushToExistDb::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => [
                    'commandName' => PushToExistDb::class,
                    'command' => serialize($job),
                ],
                'createdAt' => now()->timestamp,
                'delay' => null,
            ]),
            'exception' => "App\\Exceptions\\HttpResponse: Request failed. in /var/www/webdav/app/Jobs/PushToExistDb.php:42\nStack trace:\n#0 {main}",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_an_admin_can_retry_a_failed_push(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        file_put_contents($this->project->data_folder_location . '/foo.xml', '<root/>');
        $uuid = $this->insertFailedExistDbPush($this->project, 'foo.xml');

        $response = $this->actingAs($this->admin)->postJson(
            route('projects.retry-failed-push', ['projectId' => $this->project->id, 'uuid' => $uuid])
        );

        $response->assertOk();
        $response->assertJson(['success' => true, 'error' => null]);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_a_failed_retry_reports_the_error_and_keeps_the_row(): void
    {
        Http::fake(['*' => Http::response('server exploded', 500)]);
        file_put_contents($this->project->data_folder_location . '/foo.xml', '<root/>');
        $uuid = $this->insertFailedExistDbPush($this->project, 'foo.xml');

        $response = $this->actingAs($this->admin)->postJson(
            route('projects.retry-failed-push', ['projectId' => $this->project->id, 'uuid' => $uuid])
        );

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_a_non_admin_cannot_retry_a_failed_push(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $uuid = $this->insertFailedExistDbPush($this->project, 'foo.xml');

        $response = $this->actingAs($user)->postJson(
            route('projects.retry-failed-push', ['projectId' => $this->project->id, 'uuid' => $uuid])
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_an_unknown_uuid_is_a_404(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            route('projects.retry-failed-push', ['projectId' => $this->project->id, 'uuid' => (string) Str::uuid()])
        );

        $response->assertNotFound();
    }

    public function test_a_uuid_belonging_to_a_different_project_is_a_404(): void
    {
        $otherProject = Project::factory()->create();
        $uuid = $this->insertFailedExistDbPush($otherProject, 'foo.xml');

        $response = $this->actingAs($this->admin)->postJson(
            route('projects.retry-failed-push', ['projectId' => $this->project->id, 'uuid' => $uuid])
        );

        $response->assertNotFound();
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_retry_all_streams_a_line_per_file_and_finishes_with_done_even_when_one_fails(): void
    {
        file_put_contents($this->project->data_folder_location . '/good.xml', '<good/>');
        file_put_contents($this->project->data_folder_location . '/bad.xml', '<bad/>');
        $goodUuid = $this->insertFailedExistDbPush($this->project, 'good.xml');
        $badUuid = $this->insertFailedExistDbPush($this->project, 'bad.xml');

        Http::fake([
            'https://exist.example.com/rest/db/data/good.xml' => Http::response('', 200),
            'https://exist.example.com/rest/db/data/bad.xml' => Http::response('server exploded', 500),
        ]);

        $response = $this->actingAs($this->admin)->get(
            route('projects.retry-failed-pushes', ['projectId' => $this->project->id])
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('"file":"good.xml","success":true', $content);
        $this->assertStringContainsString('"file":"bad.xml","success":false', $content);
        $this->assertStringContainsString('data: [DONE]', $content);
        $this->assertStringNotContainsString('event: app-error', $content);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $goodUuid]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $badUuid]);
    }

    public function test_a_non_admin_cannot_retry_all_failed_pushes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(
            route('projects.retry-failed-pushes', ['projectId' => $this->project->id])
        );

        $response->assertForbidden();
    }
}
