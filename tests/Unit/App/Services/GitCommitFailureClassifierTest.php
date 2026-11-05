<?php

namespace Tests\Unit\App\Services;

use App\Services\GitCommitFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitCommitFailureClassifierTest extends TestCase
{
    public function test_it_extracts_the_stale_index_lock_path(): void
    {
        $output = "fatal: Unable to create '/data/repo/.git/index.lock': File exists.\n\n"
            . "Another git process seems to be running in this repository, e.g.\n"
            . "an editor opened by 'git commit'. Please make sure all processes\n"
            . "are terminated then try again.";

        $this->assertSame(
            '/data/repo/.git/index.lock',
            GitCommitFailureClassifier::staleLockPath($output),
        );
    }

    public function test_it_extracts_a_head_lock_path_from_a_failed_checkout(): void
    {
        $output = "fatal: Unable to create '/data/repo/.git/HEAD.lock': File exists.";

        $this->assertSame(
            '/data/repo/.git/HEAD.lock',
            GitCommitFailureClassifier::staleLockPath($output),
        );
    }

    #[DataProvider('nonLockFailureOutputProvider')]
    public function test_non_lock_failures_return_null(string $output): void
    {
        $this->assertNull(GitCommitFailureClassifier::staleLockPath($output));
    }

    public static function nonLockFailureOutputProvider(): array
    {
        return [
            'nothing to commit' => ['nothing to commit, working tree clean'],
            'no such branch' => ["error: pathspec 'does-not-exist' did not match any file(s) known to git"],
            'permission denied' => ['fatal: could not open /data/repo/.git/index for writing: Permission denied'],
        ];
    }

    public function test_it_recognizes_nothing_to_commit(): void
    {
        $this->assertTrue(GitCommitFailureClassifier::isNothingToCommit('nothing to commit, working tree clean'));
    }

    public function test_it_does_not_treat_a_real_failure_as_nothing_to_commit(): void
    {
        $this->assertFalse(GitCommitFailureClassifier::isNothingToCommit(
            "fatal: Unable to create '/data/repo/.git/index.lock': File exists."
        ));
    }
}
