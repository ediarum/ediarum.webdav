<?php

namespace Tests\Unit\Listeners;

use App\Events\DataChange;
use App\Jobs\PushToEdiarumServices;
use App\Jobs\PushToExistDb;
use App\Listeners\PushDataChanges;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PushDataChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ediarum_services.url' => null,
            'services.ediarum_services.api_key' => null,
        ]);
    }

    private function configureEdiarumServices(?string $url = 'https://services.example.com', ?string $key = 'secret'): void
    {
        config([
            'services.ediarum_services.url' => $url,
            'services.ediarum_services.api_key' => $key,
        ]);
    }

    public function test_dispatches_neither_job_when_nothing_is_configured(): void
    {
        Bus::fake();
        $project = Project::factory()->create();

        (new PushDataChanges())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToEdiarumServices::class);
        Bus::assertNotDispatched(PushToExistDb::class);
    }

    public function test_dispatches_ediarum_services_job_on_the_ediarum_services_queue_when_configured_and_switched_on(): void
    {
        $this->configureEdiarumServices();
        Bus::fake();
        $project = Project::factory()->create(['push_to_ediarum_services' => true]);

        (new PushDataChanges())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml', null, 'abc123'));

        Bus::assertDispatched(PushToEdiarumServices::class, fn ($job) => $job->queue === 'ediarum-services' && $job->commit === 'abc123');
        Bus::assertNotDispatched(PushToExistDb::class);
    }

    public function test_does_not_dispatch_ediarum_services_job_when_the_project_is_switched_off(): void
    {
        $this->configureEdiarumServices();
        Bus::fake();

        (new PushDataChanges())->handle(new DataChange('user@example.com', Project::factory()->create(['push_to_ediarum_services' => false]), 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToEdiarumServices::class);
    }

    public function test_does_not_dispatch_ediarum_services_job_without_the_api_key(): void
    {
        $this->configureEdiarumServices(key: null);
        Bus::fake();

        (new PushDataChanges())->handle(new DataChange('user@example.com', Project::factory()->create(['push_to_ediarum_services' => true]), 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToEdiarumServices::class);
    }

    public function test_does_not_dispatch_ediarum_services_job_without_the_url(): void
    {
        $this->configureEdiarumServices(url: null);
        Bus::fake();

        (new PushDataChanges())->handle(new DataChange('user@example.com', Project::factory()->create(['push_to_ediarum_services' => true]), 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToEdiarumServices::class);
    }

    public function test_dispatches_exist_db_job_on_the_exist_db_queue_when_configured(): void
    {
        Bus::fake();
        $project = Project::factory()->create([
            'exist_base_url' => 'https://exist.example.com',
            'exist_data_path' => '/db/data',
            'exist_username' => 'admin',
        ]);

        (new PushDataChanges())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertNotDispatched(PushToEdiarumServices::class);
        Bus::assertDispatched(PushToExistDb::class, fn ($job) => $job->queue === 'exist-db');
    }

    public function test_dispatches_both_jobs_when_both_are_configured(): void
    {
        $this->configureEdiarumServices();
        Bus::fake();
        $project = Project::factory()->create([
            'push_to_ediarum_services' => true,
            'exist_base_url' => 'https://exist.example.com',
            'exist_data_path' => '/db/data',
            'exist_username' => 'admin',
        ]);

        (new PushDataChanges())->handle(new DataChange('user@example.com', $project, 'afterCreateFile', 'foo.xml'));

        Bus::assertDispatched(PushToEdiarumServices::class);
        Bus::assertDispatched(PushToExistDb::class);
    }
}
