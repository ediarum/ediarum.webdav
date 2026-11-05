<?php

namespace Tests\Feature;

use App\Exceptions\HttpResponse;
use App\Exceptions\UnknownWebDavHook;
use App\Jobs\PushToExistDb;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushToExistDbTest extends TestCase
{
    use RefreshDatabase;

    protected string $stubPath;

    /** @var string[] */
    private array $createdDataFolders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubPath = base_path('tests/Feature/test_files');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDataFolders as $folder) {
            File::deleteDirectory($folder);
        }

        parent::tearDown();
    }

    public function test_text_xml_is_translated_to_application_xml()
    {
        $path = "{$this->stubPath}/example.xml";
        $result = PushToExistDb::setMimeType($path);

        $this->assertEquals('application/xml', $result);
    }

    public function test_jpg_is_translated_to_image_jpeg(): void
    {
        // Regression case: setMimeType() and ExistDbClient::createResource() used to maintain
        // independent content-type maps that had already drifted apart on this exact extension --
        // this one had 'jpeg' but not 'jpg', silently falling through to File::mimeType() detection
        // for .jpg files while ExistDbClient's map (with both) took the fast path. Both now share
        // WebDavContentTypeMap, so this asserts the fast path is actually taken.
        $path = "{$this->stubPath}/does-not-need-to-exist.jpg";

        $this->assertEquals('image/jpeg', PushToExistDb::setMimeType($path));
    }

    public function test_application_json_remains_unchanged()
    {
        $path = "{$this->stubPath}/example.json";
        $result = PushToExistDb::setMimeType($path);

        $this->assertEquals('application/json', $result);
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

    public function test_after_create_file_puts_the_file_with_the_right_url_and_content_type(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        (new PushToExistDb($project, 'afterCreateFile', 'foo.xml'))->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://exist.example.com/rest/db/data/foo.xml'
                && $request->method() === 'PUT'
                && $request->header('Content-Type')[0] === 'application/xml'
                && $request->body() === '<root/>';
        });
    }

    public function test_after_unbind_deletes_the_path(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();

        (new PushToExistDb($project, 'afterUnbind', 'foo.xml'))->handle();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://exist.example.com/rest/db/data/foo.xml');
    }

    public function test_after_move_of_a_single_file_puts_only_that_file(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/bar.xml', '<root/>');

        (new PushToExistDb($project, 'afterMove', 'foo.xml', 'bar.xml'))->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://exist.example.com/rest/db/data/bar.xml');
    }

    public function test_after_move_of_a_directory_puts_every_file_under_it(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $project = $this->existProject();
        File::ensureDirectoryExists($project->data_folder_location . '/sub');
        file_put_contents($project->data_folder_location . '/sub/one.xml', '<one/>');
        file_put_contents($project->data_folder_location . '/sub/two.xml', '<two/>');

        (new PushToExistDb($project, 'afterMove', 'old', 'sub'))->handle();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://exist.example.com/rest/db/data/sub/one.xml');
        Http::assertSent(fn ($request) => $request->url() === 'https://exist.example.com/rest/db/data/sub/two.xml');
    }

    public function test_unknown_event_throws(): void
    {
        $project = $this->existProject();

        $this->expectException(UnknownWebDavHook::class);

        (new PushToExistDb($project, 'somethingElse', 'foo.xml'))->handle();
    }

    public function test_a_failed_http_response_throws(): void
    {
        Http::fake(['*' => Http::response('server exploded', 500)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        try {
            (new PushToExistDb($project, 'afterCreateFile', 'foo.xml'))->handle();
            $this->fail('Expected an HttpResponse exception.');
        } catch (HttpResponse $e) {
            $this->assertStringContainsString('status 500', $e->getMessage());
            $this->assertStringContainsString('response body: "server exploded"', $e->getMessage());
        }
    }

    public function test_a_failed_http_response_with_an_empty_body_says_so_explicitly(): void
    {
        // eXist-db's REST API often answers a 400 with a near-empty body -- the message must say
        // plainly that there was no response text, not leave it ambiguous whether the body was
        // truly empty or just happened to be terse.
        Http::fake(['*' => Http::response('', 400)]);
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        try {
            (new PushToExistDb($project, 'afterCreateFile', 'foo.xml'))->handle();
            $this->fail('Expected an HttpResponse exception.');
        } catch (HttpResponse $e) {
            $this->assertStringContainsString('status 400', $e->getMessage());
            $this->assertStringContainsString('no response body', $e->getMessage());
        }
    }

    public function test_an_unresponsive_exist_db_throws_a_readable_error(): void
    {
        // Simulates eXist-db never answering at all (down, firewalled, network black hole) --
        // as opposed to test_a_failed_http_response_throws, which simulates it answering with
        // an error status.
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $project = $this->existProject();
        file_put_contents($project->data_folder_location . '/foo.xml', '<root/>');

        try {
            (new PushToExistDb($project, 'afterCreateFile', 'foo.xml'))->handle();
            $this->fail('Expected an HttpResponse exception.');
        } catch (HttpResponse $e) {
            $this->assertStringContainsString('did not respond', $e->getMessage());
            $this->assertStringContainsString('cURL error 28: Operation timed out', $e->getMessage());
        }
    }
}
