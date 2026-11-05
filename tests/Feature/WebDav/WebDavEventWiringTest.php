<?php

namespace Tests\Feature\WebDav;

use App\Events\DataChange;
use Illuminate\Support\Facades\Event;

class WebDavEventWiringTest extends WebDavTestCase
{
    public function test_put_new_file_dispatches_after_create_file(): void
    {
        Event::fake();

        $this->webdav('PUT', '/foo.xml', [], '<root/>')->assertStatus(201);

        Event::assertDispatched(DataChange::class, function (DataChange $event) {
            return $event->webDavEvent === 'afterCreateFile'
                && $event->sourcePath === 'foo.xml'
                && $event->destinationPath === null
                && $event->project->is($this->project);
        });
    }

    public function test_put_overwrite_dispatches_after_write_content(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<old/>');
        Event::fake();

        $this->webdav('PUT', '/foo.xml', [], '<new/>')->assertStatus(204);

        Event::assertDispatched(DataChange::class, fn (DataChange $event) => $event->webDavEvent === 'afterWriteContent'
            && $event->sourcePath === 'foo.xml');
    }

    public function test_delete_dispatches_after_unbind(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        Event::fake();

        $this->webdav('DELETE', '/foo.xml')->assertStatus(204);

        Event::assertDispatched(DataChange::class, fn (DataChange $event) => $event->webDavEvent === 'afterUnbind'
            && $event->sourcePath === 'foo.xml');
    }

    public function test_move_dispatches_after_move_with_both_paths(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');
        Event::fake();

        $this->webdav('MOVE', '/foo.xml', [
            'Destination' => "/connection/{$this->project->slug}/bar.xml",
        ])->assertStatus(201);

        Event::assertDispatched(DataChange::class, fn (DataChange $event) => $event->webDavEvent === 'afterMove'
            && $event->sourcePath === 'foo.xml'
            && $event->destinationPath === 'bar.xml');
    }

    public function test_data_change_user_is_the_authenticated_users_email(): void
    {
        Event::fake();

        $this->webdav('PUT', '/foo.xml', [], '<root/>')->assertStatus(201);

        Event::assertDispatched(DataChange::class, function (DataChange $event) {
            return $event->user === $this->user->email && $event->user !== '';
        });
    }
}
