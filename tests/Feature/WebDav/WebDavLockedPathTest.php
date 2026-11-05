<?php

namespace Tests\Feature\WebDav;

use App\Models\ProjectLockedPath;
use Illuminate\Support\Facades\DB;

class WebDavLockedPathTest extends WebDavTestCase
{
    public function test_put_of_a_new_file_under_a_locked_path_is_rejected(): void
    {
        mkdir($this->fullPath('locked'));
        $this->lockPath('locked');

        $response = $this->webdav('PUT', '/locked/foo.xml', [], '<root/>');

        $response->assertStatus(423);
        $this->assertFileDoesNotExist($this->fullPath('locked/foo.xml'));
    }

    public function test_put_overwriting_an_existing_file_under_a_locked_path_is_rejected(): void
    {
        mkdir($this->fullPath('locked'));
        file_put_contents($this->fullPath('locked/foo.xml'), '<old/>');
        $this->lockPath('locked');

        $response = $this->webdav('PUT', '/locked/foo.xml', [], '<new/>');

        $response->assertStatus(423);
        $this->assertSame('<old/>', file_get_contents($this->fullPath('locked/foo.xml')));
    }

    public function test_delete_of_a_file_under_a_locked_path_is_rejected(): void
    {
        mkdir($this->fullPath('locked'));
        file_put_contents($this->fullPath('locked/foo.xml'), '<root/>');
        $this->lockPath('locked');

        $response = $this->webdav('DELETE', '/locked/foo.xml');

        $response->assertStatus(423);
        $this->assertFileExists($this->fullPath('locked/foo.xml'));
    }

    public function test_move_with_the_destination_under_a_locked_path_is_rejected(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        mkdir($this->fullPath('locked'));
        $this->lockPath('locked');

        $response = $this->webdav('MOVE', '/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/locked/foo.xml",
        ]);

        $response->assertStatus(423);
        $this->assertFileExists($this->fullPath('foo.xml'));
        $this->assertFileDoesNotExist($this->fullPath('locked/foo.xml'));
    }

    public function test_move_with_the_source_under_a_locked_path_is_rejected(): void
    {
        mkdir($this->fullPath('locked'));
        file_put_contents($this->fullPath('locked/foo.xml'), '<root/>');
        $this->lockPath('locked');

        $response = $this->webdav('MOVE', '/locked/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/bar.xml",
        ]);

        $response->assertStatus(423);
        $this->assertFileExists($this->fullPath('locked/foo.xml'));
        $this->assertFileDoesNotExist($this->fullPath('bar.xml'));
    }

    public function test_copy_with_the_destination_under_a_locked_path_is_rejected(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        mkdir($this->fullPath('locked'));
        $this->lockPath('locked');

        $response = $this->webdav('COPY', '/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/locked/foo.xml",
        ]);

        $response->assertStatus(423);
        $this->assertFileDoesNotExist($this->fullPath('locked/foo.xml'));
    }

    public function test_lock_of_a_file_under_a_locked_path_is_rejected_and_creates_no_editing_lock(): void
    {
        mkdir($this->fullPath('locked'));
        file_put_contents($this->fullPath('locked/foo.xml'), '<root/>');
        $this->lockPath('locked');

        $response = $this->webdav('LOCK', '/locked/foo.xml', [], $this->lockRequestBody());

        $response->assertStatus(423);
        $this->assertSame(0, DB::table("locks_{$this->project->id}")->count());
    }

    public function test_a_sibling_path_outside_the_lock_is_unaffected(): void
    {
        mkdir($this->fullPath('locked'));
        $this->lockPath('locked');

        $response = $this->webdav('PUT', '/sibling.xml', [], '<root/>');

        $response->assertStatus(201);
        $this->assertFileExists($this->fullPath('sibling.xml'));
    }

    private function lockPath(string $path): void
    {
        ProjectLockedPath::create([
            'project_id' => $this->project->id,
            'path' => $path,
            'locked_by' => 'admin@example.com',
        ]);
    }

    private function lockRequestBody(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<D:lockinfo xmlns:D="DAV:">
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
  <D:owner><D:href>mailto:test@example.com</D:href></D:owner>
</D:lockinfo>
XML;
    }
}
