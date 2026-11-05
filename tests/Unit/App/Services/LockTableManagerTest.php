<?php

namespace Tests\Unit\App\Services;

use App\Services\LockTableManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LockTableManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_name_for_project(): void
    {
        $this->assertSame('locks_42', LockTableManager::tableNameFor(42));
    }

    public function test_ensure_table_for_project_is_idempotent(): void
    {
        LockTableManager::ensureTableForProject(999);
        LockTableManager::ensureTableForProject(999);

        $this->assertTrue(Schema::hasTable('locks_999'));
        $this->assertSame(
            ['id', 'owner', 'timeout', 'created', 'token', 'scope', 'depth', 'uri'],
            Schema::getColumnListing('locks_999')
        );
    }

    public function test_drop_table_for_project_on_a_non_existent_table_is_a_no_op(): void
    {
        $this->assertFalse(Schema::hasTable('locks_no_such_project'));

        LockTableManager::dropTableForProject(123456789);

        $this->assertFalse(Schema::hasTable('locks_123456789'));
    }

    public function test_has_active_locks_under_path_matches_the_exact_path(): void
    {
        LockTableManager::ensureTableForProject(1);
        $this->insertLock(1, 'folder/file.xml');

        $this->assertTrue(LockTableManager::hasActiveLocksUnderPath(1, 'folder/file.xml'));
    }

    public function test_has_active_locks_under_path_matches_a_descendant(): void
    {
        LockTableManager::ensureTableForProject(2);
        $this->insertLock(2, 'folder/sub/file.xml');

        $this->assertTrue(LockTableManager::hasActiveLocksUnderPath(2, 'folder'));
    }

    public function test_has_active_locks_under_path_ignores_an_unrelated_path(): void
    {
        LockTableManager::ensureTableForProject(3);
        $this->insertLock(3, 'other-folder/file.xml');

        $this->assertFalse(LockTableManager::hasActiveLocksUnderPath(3, 'folder'));
    }

    public function test_has_active_locks_under_path_ignores_a_string_prefix_that_is_not_a_real_descendant(): void
    {
        LockTableManager::ensureTableForProject(4);
        $this->insertLock(4, 'folder-other/file.xml');

        $this->assertFalse(LockTableManager::hasActiveLocksUnderPath(4, 'folder'));
    }

    private function insertLock(int $projectId, string $uri): void
    {
        DB::table(LockTableManager::tableNameFor($projectId))->insert([
            'owner' => 'user@example.com',
            'timeout' => 1800,
            'created' => time(),
            'token' => 'urn:uuid:' . \Illuminate\Support\Str::uuid(),
            'scope' => 1,
            'depth' => 0,
            'uri' => $uri,
        ]);
    }
}
