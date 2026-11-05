<?php

namespace App\Services;

use App\Exceptions\ExistDBResourceNotFound;
use App\Helpers\ExistDbClient;
use App\Helpers\FolderContents;

class ExistDBSyncService
{
    protected ExistDbClient $existDBClient;
    protected string $filesystem_data_path;
    protected string $exist_data_path;

    public function __construct(ExistDbClient $existDBClient, string $filesystem_data_path, string $exist_data_path)
    {
        $this->existDBClient = $existDBClient;
        $this->filesystem_data_path = $filesystem_data_path;
        $this->exist_data_path = $exist_data_path;
    }

    public function syncFolder(?string $relativePath, callable $output)
    {
        $fileSystem = new FolderContents();
        $fileSystem->parseFileSystemFolderContents(
            $this->filesystem_data_path . '/' . $relativePath
        );

        $existPath = $relativePath ? $this->exist_data_path . "/" . $relativePath : $this->exist_data_path;
        $output("Getting folder contents from exist: $existPath");

        $existDBFolder = $this->existDBClient->getDirectory($existPath);

        $justExistDBFiles = $existDBFolder->getFiles()->diff($fileSystem->getFiles());
        $justExistDBFolders = $existDBFolder->getFolders()->diff($fileSystem->getFolders());

        foreach ($justExistDBFiles as $file) {
            $filePath = $relativePath ? $relativePath . '/' . $file : $file;
            $output("Deleting file $filePath");
                $this->existDBClient->deleteResource($this->exist_data_path . "/" . $filePath);
        }

        foreach ($justExistDBFolders as $folder) {
            $path = $relativePath ? $relativePath . '/' . $folder : $folder;
            $output("Deleting folder $path");
            $this->existDBClient->deleteResource($this->exist_data_path . "/" . $path);
        }

        foreach ($fileSystem->getFiles() as $file) {
            $filePath = $relativePath ? $relativePath . '/' . $file : $file;
            $absolutePath = $this->filesystem_data_path . '/' . $filePath;
            $existPath = $this->exist_data_path . '/' . $filePath;

            $output("Pushing file $filePath");
            $this->existDBClient->createResource($existPath, $absolutePath);
        }

        foreach ($fileSystem->getFolders() as $folder) {
            $newPath = $relativePath ? $relativePath . '/' . $folder : $folder;
            $this->syncFolder($newPath, $output);
        }
    }
}
