<?php

namespace Tests\Unit\App\Sabre;

use App\Events\DataChange;
use App\Models\Project;
use App\Sabre\WebDavEventBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WebDavEventBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_forwards_simple_events_without_a_destination_path(): void
    {
        Event::fake();
        $project = Project::factory()->create();

        WebDavEventBridge::forward('afterCreateFile', $project, 'user@example.com', 'foo.xml');

        Event::assertDispatched(DataChange::class, function (DataChange $event) use ($project) {
            return $event->user === 'user@example.com'
                && $event->project->is($project)
                && $event->webDavEvent === 'afterCreateFile'
                && $event->sourcePath === 'foo.xml'
                && $event->destinationPath === null;
        });
    }

    public function test_forwards_after_move_with_both_paths(): void
    {
        Event::fake();
        $project = Project::factory()->create();

        WebDavEventBridge::forward('afterMove', $project, 'user@example.com', 'foo.xml', 'bar.xml');

        Event::assertDispatched(DataChange::class, function (DataChange $event) {
            return $event->webDavEvent === 'afterMove'
                && $event->sourcePath === 'foo.xml'
                && $event->destinationPath === 'bar.xml';
        });
    }
}
