<?php

namespace Tests\Unit\App\Services;

use App\Helpers\ExistDbClient;
use App\Helpers\FolderContents;
use App\Services\ExistDBSyncService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ExistDBSyncServiceTest extends TestCase
{
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataPath = storage_path('framework/testing/disks/sync-' . uniqid());
        File::ensureDirectoryExists($this->dataPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataPath);
        parent::tearDown();
    }

    private function folderContents(array $files = [], array $folders = []): FolderContents
    {
        $contents = new FolderContents();
        foreach ($files as $file) {
            $contents->getFiles()->add($file);
        }
        foreach ($folders as $folder) {
            $contents->getFolders()->add($folder);
        }

        return $contents;
    }

    public function test_a_file_present_locally_but_not_in_exist_db_gets_created(): void
    {
        file_put_contents($this->dataPath . '/foo.xml', '<root/>');

        /** @var ExistDbClient&MockObject $client */
        $client = $this->createMock(ExistDbClient::class);
        $client->method('getDirectory')->willReturn($this->folderContents());
        $client->expects($this->once())->method('createResource')
            ->with('/db/data/foo.xml', $this->dataPath . '/foo.xml');
        $client->expects($this->never())->method('deleteResource');

        (new ExistDBSyncService($client, $this->dataPath, '/db/data'))->syncFolder(null, fn () => null);
    }

    public function test_a_file_in_exist_db_but_not_locally_gets_deleted(): void
    {
        /** @var ExistDbClient&MockObject $client */
        $client = $this->createMock(ExistDbClient::class);
        $client->method('getDirectory')->willReturn($this->folderContents(files: ['orphan.xml']));
        $client->expects($this->once())->method('deleteResource')->with('/db/data/orphan.xml');
        $client->expects($this->never())->method('createResource');

        (new ExistDBSyncService($client, $this->dataPath, '/db/data'))->syncFolder(null, fn () => null);
    }

    public function test_recurses_into_subfolders(): void
    {
        File::ensureDirectoryExists($this->dataPath . '/sub');
        file_put_contents($this->dataPath . '/sub/nested.xml', '<root/>');

        /** @var ExistDbClient&MockObject $client */
        $client = $this->createMock(ExistDbClient::class);
        $client->method('getDirectory')->willReturnCallback(function (string $path) {
            // Root has the 'sub' folder but nothing in exist-db yet at either level.
            return $this->folderContents();
        });
        $client->expects($this->once())->method('createResource')
            ->with('/db/data/sub/nested.xml', $this->dataPath . '/sub/nested.xml');

        (new ExistDBSyncService($client, $this->dataPath, '/db/data'))->syncFolder(null, fn () => null);
    }

    public function test_an_empty_project_folder_creates_or_deletes_nothing(): void
    {
        /** @var ExistDbClient&MockObject $client */
        $client = $this->createMock(ExistDbClient::class);
        $client->method('getDirectory')->willReturn($this->folderContents());
        $client->expects($this->never())->method('createResource');
        $client->expects($this->never())->method('deleteResource');

        (new ExistDBSyncService($client, $this->dataPath, '/db/data'))->syncFolder(null, fn () => null);
    }
}
