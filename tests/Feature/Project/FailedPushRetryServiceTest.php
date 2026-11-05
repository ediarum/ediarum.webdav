<?php

namespace Tests\Feature\Project;

use App\Exceptions\HttpResponse;
use App\Jobs\PushToExistDb;
use App\Jobs\PushToGitRemote;
use App\Models\FailedJob;
use App\Models\Project;
use App\Services\FailedPushRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedPushRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $createdDataFolders = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDataFolders as $folder) {
            File::deleteDirectory($folder);
        }

        parent::tearDown();
    }

    private function existProject(): Project
    {
        $project = Project::factory()->create([
            'exist_base_url' => 'https://exist.example.com',
            'exist_data_path' => '/db/data',
            'exist_username' => 'admin',
            'exist_password' => 'secret',
        ]);
        File::ensureDirectoryExists($project->data_folder_location);
        $this->createdDataFolders[] = $project->data_folder_location;

        return $project;
    }

    /**
     * Inserts a failed_jobs row shaped exactly like the one Laravel's database queue driver writes
     * for a real PushToExistDb failure (confirmed against the shape Tests\Feature\Admin\AdminPanelTest
     * already uses for PushToGitRemote). Returns the uuid.
     */
    private function insertFailedExistDbPush(
        Project $project,
        string $sourcePath,
        ?string $destinationPath = null,
        string $webDavEvent = 'afterCreateFile',
        string $exceptionMessage = 'Request failed.',
        $failedAt = null,
    ): string {
        $job = new PushToExistDb($project, $webDavEvent, $sourcePath, $destinationPath);
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
            'exception' => "App\\Exceptions\\HttpResponse: {$exceptionMessage} in /var/www/webdav/app/Jobs/PushToExistDb.php:42\nStack trace:\n#0 {main}",
            'failed_at' => $failedAt ?? now(),
        ]);

        return $uuid;
    }

    private function insertFailedGitRemotePush(Project $project): string
    {
        $job = new PushToGitRemote($project);
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'git-remote',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => PushToGitRemote::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => [
                    'commandName' => PushToGitRemote::class,
                    'command' => serialize($job),
                ],
                'createdAt' => now()->timestamp,
                'delay' => null,
            ]),
            'exception' => "Exception: push failed in /var/www/webdav/app/Jobs/PushToGitRemote.php:50\nStack trace:\n#0 {main}",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_command_decodes_the_serialized_push_job(): void
    {
        $project = $this->existProject();
        $uuid = $this->insertFailedExistDbPush($project, 'foo/bar.xml');

        $job = FailedJob::where('uuid', $uuid)->firstOrFail();
        $command = $job->command();

        $this->assertInstanceOf(PushToExistDb::class, $command);
        $this->assertSame('foo/bar.xml', $command->sourcePath);
        $this->assertSame($project->id, $command->project->id);
    }

    public function test_for_project_only_returns_rows_for_that_project(): void
    {
        $projectA = $this->existProject();
        $projectB = $this->existProject();
        $uuidA = $this->insertFailedExistDbPush($projectA, 'a.xml');
        $this->insertFailedExistDbPush($projectB, 'b.xml');

        $results = FailedJob::forProject($projectA->id);

        $this->assertCount(1, $results);
        $this->assertSame($uuidA, $results->first()->uuid());
    }

    public function test_for_project_excludes_other_queues(): void
    {
        $project = $this->existProject();
        $this->insertFailedGitRemotePush($project);

        $results = FailedJob::forProject($project->id);

        $this->assertCount(0, $results);
    }

    public function test_retry_file_replays_and_clears_the_row_on_success(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');
        $uuid = $this->insertFailedExistDbPush($project, 'foo.xml');

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertSame(['success' => true, 'error' => null], $result);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_retry_file_clears_every_row_for_the_same_source_path_on_success(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');
        $uuidOne = $this->insertFailedExistDbPush($project, 'foo.xml', webDavEvent: 'afterCreateFile', failedAt: now()->subMinute());
        $uuidTwo = $this->insertFailedExistDbPush($project, 'foo.xml', webDavEvent: 'afterWriteContent', failedAt: now());

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuidOne]);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuidTwo]);
    }

    public function test_retry_file_leaves_the_row_when_the_replay_fails(): void
    {
        Http::fake(['*' => Http::response('server exploded', 500)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');
        $uuid = $this->insertFailedExistDbPush($project, 'foo.xml');

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_retry_file_reports_a_readable_error_when_exist_db_does_not_respond(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');
        $uuid = $this->insertFailedExistDbPush($project, 'foo.xml');

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('did not respond', $result['error']);
        $this->assertStringContainsString('cURL error 28: Operation timed out', $result['error']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_retry_file_deletes_rows_older_than_the_display_cap(): void
    {
        // forProject() (used for display) caps at the 50 most recent rows -- retryFile() must not
        // rely on it for deletion, or a file with an old failure buried behind 50 more recent
        // failures on *other* files would have that old row survive a successful retry and
        // resurface once the newer duplicate is gone.
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        $oldUuid = $this->insertFailedExistDbPush($project, 'foo.xml', failedAt: now()->subDays(2));

        for ($i = 0; $i < 50; $i++) {
            $this->insertFailedExistDbPush($project, "other-{$i}.xml", failedAt: now()->subMinutes($i));
        }

        $recentUuid = $this->insertFailedExistDbPush($project, 'foo.xml', failedAt: now());

        // Sanity check that the scenario actually exercises the cap: the old row must not be in
        // the capped, display-facing list.
        $this->assertFalse(FailedJob::forProject($project->id)->pluck('uuid')->contains($oldUuid));

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $oldUuid]);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $recentUuid]);
    }

    public function test_retry_file_catches_non_http_exceptions(): void
    {
        $project = $this->existProject();
        // No file created on disk for foo.xml -- putFile()'s File::get() throws.
        $this->insertFailedExistDbPush($project, 'foo.xml');

        $result = (new FailedPushRetryService())->retryFile($project, 'foo.xml');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_retry_file_returns_an_error_when_nothing_matches(): void
    {
        $project = $this->existProject();

        $result = (new FailedPushRetryService())->retryFile($project, 'does-not-exist.xml');

        $this->assertSame(['success' => false, 'error' => 'No matching failed push found.'], $result);
    }

    public function test_retry_all_for_project_is_best_effort_and_dedupes_by_file(): void
    {
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/one.xml', '<one/>');
        file_put_contents($project->data_folder_location . '/two.xml', '<two/>');
        file_put_contents($project->data_folder_location . '/three.xml', '<three/>');

        // one.xml has two failed rows -- retryAllForProject must attempt it once, not twice.
        $this->insertFailedExistDbPush($project, 'one.xml', webDavEvent: 'afterCreateFile');
        $this->insertFailedExistDbPush($project, 'one.xml', webDavEvent: 'afterWriteContent');
        $this->insertFailedExistDbPush($project, 'two.xml');
        $this->insertFailedExistDbPush($project, 'three.xml');

        Http::fake([
            'https://exist.example.com/rest/db/data/one.xml' => Http::response('', 200),
            'https://exist.example.com/rest/db/data/two.xml' => Http::response('server exploded', 500),
            'https://exist.example.com/rest/db/data/three.xml' => Http::response('', 200),
        ]);

        $calls = [];
        (new FailedPushRetryService())->retryAllForProject($project, function ($file, $success, $error) use (&$calls) {
            $calls[] = [$file, $success, $error];
        });

        $this->assertCount(3, $calls, 'one.xml should only be attempted once despite having two failed rows.');

        $byFile = collect($calls)->keyBy(0);
        $this->assertTrue($byFile['one.xml'][1]);
        $this->assertFalse($byFile['two.xml'][1]);
        $this->assertTrue($byFile['three.xml'][1]);

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', ['queue' => 'exist-db']);
        $this->assertSame('two.xml', FailedJob::sole()->command()->sourcePath);
    }
}
