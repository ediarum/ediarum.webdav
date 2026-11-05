<?php

namespace Tests\Unit\App\Helpers;

use App\Exceptions\ExistDBResourceNotFound;
use App\Helpers\ExistDbClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExistDbClientTest extends TestCase
{
    private function client(): ExistDbClient
    {
        return new ExistDbClient('https://exist.example.com', 'admin', 'secret');
    }

    public function test_get_directory_on_a_404_returns_empty_folder_contents_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $contents = $this->client()->getDirectory('/db/data/missing');

        $this->assertEquals(0, $contents->getFiles()->count());
        $this->assertEquals(0, $contents->getFolders()->count());
    }

    public function test_get_directory_parses_a_successful_response(): void
    {
        $xml = file_get_contents(base_path('tests/resources/example_folder_contents.xml'));
        Http::fake(['*' => Http::response($xml, 200)]);

        $contents = $this->client()->getDirectory('/db/data/Kleintexte');

        $this->assertEquals(5, $contents->getFolders()->count());
        $this->assertEquals(1, $contents->getFiles()->count());
    }

    public function test_delete_resource_on_a_404_throws_exist_db_resource_not_found(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectException(ExistDBResourceNotFound::class);

        $this->client()->deleteResource('/db/data/missing.xml');
    }

    public function test_delete_resource_on_another_failure_throws_a_generic_exception(): void
    {
        Http::fake(['*' => Http::response('server exploded', 500)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/status 500 \(response body: "server exploded"\)/');

        $this->client()->deleteResource('/db/data/foo.xml');
    }

    public function test_create_resource_uses_the_in_memory_body_path_for_small_files(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $path = tempnam(sys_get_temp_dir(), 'existdb-small-');
        file_put_contents($path, '<root/>');

        try {
            $this->client()->createResource('/db/data/foo.xml', $path);

            Http::assertSent(function ($request) {
                return $request->method() === 'PUT'
                    && $request->body() === '<root/>'
                    && $request->header('Content-Type')[0] === 'application/octet-stream';
            });
        } finally {
            unlink($path);
        }
    }

    public function test_create_resource_uses_the_streamed_body_path_for_files_at_or_above_100kb(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $path = tempnam(sys_get_temp_dir(), 'existdb-large-');
        file_put_contents($path, str_repeat('a', 100 * 1024));

        try {
            $this->client()->createResource('/db/data/foo.xml', $path);

            Http::assertSent(function ($request) {
                return $request->method() === 'PUT'
                    && strlen($request->body()) === 100 * 1024;
            });
        } finally {
            unlink($path);
        }
    }

    public function test_create_resource_maps_known_extensions_to_their_content_type(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $path = tempnam(sys_get_temp_dir(), 'existdb-');
        $jpgPath = $path . '.jpg';
        rename($path, $jpgPath);
        file_put_contents($jpgPath, 'not really a jpeg');

        try {
            $this->client()->createResource('/db/data/foo.jpg', $jpgPath);

            Http::assertSent(fn ($request) => $request->header('Content-Type')[0] === 'image/jpeg');
        } finally {
            unlink($jpgPath);
        }
    }
}
