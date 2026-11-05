<?php

namespace Tests\Unit\Jobs;

use App\Exceptions\HttpResponse;
use App\Exceptions\UnknownWebDavHook;
use App\Jobs\PushToEdiarumServices;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushToEdiarumServicesTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $createdDataFolders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ediarum_services.url' => 'https://services.example.com/',
            'services.ediarum_services.api_key' => 'services-key',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDataFolders as $folder) {
            File::deleteDirectory($folder);
        }

        parent::tearDown();
    }

    private function ediarumProject(): Project
    {
        $project = Project::factory()->create(['slug' => 'my-edition']);
        File::ensureDirectoryExists($project->data_folder_location);
        $this->createdDataFolders[] = $project->repository_path;

        return $project;
    }

    /**
     * The multipart parts of a sent request, as name => contents.
     *
     * @return array<string, string>
     */
    private static function parts(Request $request): array
    {
        return collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => (string) $part['contents']])->all();
    }

    public function test_after_create_file_posts_a_created_event_with_the_file(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        (new PushToEdiarumServices($project, 'afterCreateFile', 'foo.xml', null, 'abc123'))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://services.example.com/files'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer services-key')
            && self::parts($request) === [
                'event' => 'created',
                'project' => 'my-edition',
                'commit' => 'abc123',
                'path' => 'data/foo.xml',
                'file' => '<root/>',
            ]);
    }

    public function test_after_write_content_posts_a_modified_event(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        (new PushToEdiarumServices($project, 'afterWriteContent', 'foo.xml', null, 'abc123'))->handle();

        Http::assertSent(fn (Request $request) => self::parts($request)['event'] === 'modified');
    }

    public function test_after_unbind_posts_a_deleted_event_without_a_file(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();

        (new PushToEdiarumServices($project, 'afterUnbind', 'foo.xml', null, 'abc123'))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://services.example.com/files'
            && self::parts($request) === [
                'event' => 'deleted',
                'project' => 'my-edition',
                'commit' => 'abc123',
                'path' => 'data/foo.xml',
            ]);
    }

    public function test_after_move_posts_a_moved_event_with_the_previous_path_and_the_file(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();
        file_put_contents($project->data_folder_location . '/bar.xml', '<root/>');

        (new PushToEdiarumServices($project, 'afterMove', 'foo.xml', 'bar.xml', 'abc123'))->handle();

        Http::assertSent(fn (Request $request) => self::parts($request) === [
            'event' => 'moved',
            'project' => 'my-edition',
            'commit' => 'abc123',
            'path' => 'data/bar.xml',
            'previous_path' => 'data/foo.xml',
            'file' => '<root/>',
        ]);
    }

    public function test_a_moved_directory_is_reported_file_by_file(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();
        File::ensureDirectoryExists($project->data_folder_location . '/new/sub');
        file_put_contents($project->data_folder_location . '/new/a.xml', '<a/>');
        file_put_contents($project->data_folder_location . '/new/sub/b.xml', '<b/>');

        (new PushToEdiarumServices($project, 'afterMove', 'old', 'new', 'abc123'))->handle();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => self::parts($request)['path'] === 'data/new/a.xml'
            && self::parts($request)['previous_path'] === 'data/old/a.xml');
        Http::assertSent(fn (Request $request) => self::parts($request)['path'] === 'data/new/sub/b.xml'
            && self::parts($request)['previous_path'] === 'data/old/sub/b.xml');
    }

    public function test_the_commit_is_left_out_when_there_is_none(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->ediarumProject();

        (new PushToEdiarumServices($project, 'afterUnbind', 'foo.xml'))->handle();

        Http::assertSent(fn (Request $request) => !array_key_exists('commit', self::parts($request)));
    }

    public function test_unknown_event_throws(): void
    {
        $project = $this->ediarumProject();

        $this->expectException(UnknownWebDavHook::class);

        (new PushToEdiarumServices($project, 'somethingElse', 'foo.xml'))->handle();
    }

    public function test_a_failed_http_response_throws(): void
    {
        Http::fake(['*' => Http::response('server exploded', 500)]);
        $project = $this->ediarumProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        $this->expectException(HttpResponse::class);

        (new PushToEdiarumServices($project, 'afterCreateFile', 'foo.xml'))->handle();
    }
}
