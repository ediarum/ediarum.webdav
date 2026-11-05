<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh, unique directory per test so `Project::repository_path`/`data_folder_location`
        // (derived from this + a project's slug -- see planning/init-project.md Part A) always
        // resolves somewhere real and test-isolated by default. A test that needs a project to point
        // at a specific, already-prepared real repo (a git checkout, say) overrides this itself --
        // typically via `config(['app.repositories_directory' => dirname($path)])` alongside a
        // `slug` matching `basename($path)` -- which simply replaces this default for that project.
        config(['app.repositories_directory' => storage_path('framework/testing/disks/repositories-' . uniqid())]);
    }
}
