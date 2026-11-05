<?php

namespace Tests\Feature\WebDav;

class WebDavProtocolTest extends WebDavTestCase
{
    public function test_put_creates_a_new_file(): void
    {
        $response = $this->webdav('PUT', '/foo.xml', [], '<root/>');

        $response->assertStatus(201);
        $this->assertFileExists($this->fullPath('foo.xml'));
        $this->assertSame('<root/>', file_get_contents($this->fullPath('foo.xml')));
    }

    public function test_put_overwrites_an_existing_file(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<old/>');

        $response = $this->webdav('PUT', '/foo.xml', [], '<new/>');

        $response->assertStatus(204);
        $this->assertSame('<new/>', file_get_contents($this->fullPath('foo.xml')));
    }

    public function test_get_returns_an_existing_file(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('GET', '/foo.xml');

        $response->assertStatus(200);
        // Sabre\DAV\FS\File::get() always returns a stream resource, never a string, so this
        // controller's response is always the stream() branch -- getContent() would return false.
        $this->assertSame('<root/>', $response->streamedContent());
    }

    public function test_get_streams_a_large_file(): void
    {
        $content = str_repeat('0123456789', 20000); // 200KB, forces the stream() branch
        file_put_contents($this->fullPath('large.bin'), $content);

        $response = $this->webdav('GET', '/large.bin');

        $response->assertStatus(200);
        $this->assertSame($content, $response->streamedContent());
    }

    public function test_get_missing_file_returns_404(): void
    {
        $response = $this->webdav('GET', '/missing.xml');

        $response->assertStatus(404);
    }

    public function test_propfind_depth_0_on_root(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '0']);

        $response->assertStatus(207);
        $this->assertStringNotContainsString('foo.xml', $response->getContent());
    }

    public function test_propfind_depth_1_lists_files_and_folders(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        mkdir($this->fullPath('sub'));

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '1']);

        $response->assertStatus(207);
        $body = $response->getContent();
        $this->assertStringContainsString('foo.xml', $body);
        $this->assertStringContainsString('sub', $body);
    }

    public function test_mkcol_creates_a_new_folder(): void
    {
        $response = $this->webdav('MKCOL', '/newfolder');

        $response->assertStatus(201);
        $this->assertDirectoryExists($this->fullPath('newfolder'));
    }

    public function test_mkcol_on_existing_path_fails(): void
    {
        mkdir($this->fullPath('existing'));

        $response = $this->webdav('MKCOL', '/existing');

        $response->assertStatus(405);
    }

    public function test_delete_removes_a_file(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('DELETE', '/foo.xml');

        $response->assertStatus(204);
        $this->assertFileDoesNotExist($this->fullPath('foo.xml'));
    }

    public function test_delete_removes_a_non_empty_folder_recursively(): void
    {
        mkdir($this->fullPath('sub'));
        file_put_contents($this->fullPath('sub/foo.xml'), '<root/>');

        $response = $this->webdav('DELETE', '/sub');

        $response->assertStatus(204);
        $this->assertDirectoryDoesNotExist($this->fullPath('sub'));
    }

    public function test_copy_duplicates_a_file(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('COPY', '/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/bar.xml",
        ]);

        $response->assertStatus(201);
        $this->assertFileExists($this->fullPath('foo.xml'));
        $this->assertFileExists($this->fullPath('bar.xml'));
        $this->assertSame('<root/>', file_get_contents($this->fullPath('bar.xml')));
    }

    public function test_move_relocates_a_file(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('MOVE', '/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/bar.xml",
        ]);

        $response->assertStatus(201);
        $this->assertFileDoesNotExist($this->fullPath('foo.xml'));
        $this->assertFileExists($this->fullPath('bar.xml'));
    }

    public function test_put_to_locked_file_without_token_is_rejected(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        $this->webdav('LOCK', '/foo.xml', [], $this->lockRequestBody());

        $response = $this->webdav('PUT', '/foo.xml', [], '<new/>');

        $response->assertStatus(423);
    }

    public function test_put_to_locked_file_with_token_succeeds(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        $lockResponse = $this->webdav('LOCK', '/foo.xml', [], $this->lockRequestBody());
        $token = $this->extractLockToken($lockResponse->getContent());

        $response = $this->webdav('PUT', '/foo.xml', [
            'If' => "(<{$token}>)",
        ], '<new/>');

        $response->assertStatus(204);
        $this->assertSame('<new/>', file_get_contents($this->fullPath('foo.xml')));
    }

    public function test_unlock_releases_the_lock(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        $lockResponse = $this->webdav('LOCK', '/foo.xml', [], $this->lockRequestBody());
        $token = $this->extractLockToken($lockResponse->getContent());

        $unlockResponse = $this->webdav('UNLOCK', '/foo.xml', [
            'Lock-Token' => "<{$token}>",
        ]);
        $unlockResponse->assertStatus(204);

        $response = $this->webdav('PUT', '/foo.xml', [], '<new/>');
        $response->assertStatus(204);
    }

    public function test_lock_owner_is_the_authenticated_users_email(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('LOCK', '/foo.xml', [], $this->lockRequestBody());

        $response->assertStatus(200);
        $this->assertStringContainsString($this->user->email, $response->getContent());
    }

    public function test_locks_do_not_leak_across_projects(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        $this->webdav('LOCK', '/foo.xml', [], $this->lockRequestBody());

        $otherUser = \App\Models\User::factory()->create();
        $otherProject = \App\Models\Project::factory()->create();
        $otherProject->users()->attach($otherUser);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($otherProject->data_folder_location);
        file_put_contents($otherProject->data_folder_location . '/foo.xml', '<root/>');

        $response = $this->webdav('PUT', '/foo.xml', [], '<new/>', $otherUser, $otherProject);

        $response->assertStatus(204);

        \Illuminate\Support\Facades\File::deleteDirectory($otherProject->data_folder_location);
    }

    public function test_options_returns_dav_headers(): void
    {
        $response = $this->webdav('OPTIONS', '/');

        $response->assertStatus(200);
        $response->assertHeader('DAV');
        $response->assertHeader('Allow');
    }

    private function lockRequestBody(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<D:lockinfo xmlns:D="DAV:">
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
  <D:owner><D:href>test-owner</D:href></D:owner>
</D:lockinfo>
XML;
    }

    private function extractLockToken(string $lockResponseBody): string
    {
        preg_match('/opaquelocktoken:[a-f0-9\-]+/', $lockResponseBody, $matches);

        return $matches[0];
    }
}
