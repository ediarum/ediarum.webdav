<?php

namespace Tests\Unit\App\Services;

use App\Services\GitCommandBuilder;
use PHPUnit\Framework\TestCase;

class GitCommandBuilderTest extends TestCase
{
    public function test_the_shell_treats_a_repo_path_containing_a_space_as_one_argument(): void
    {
        $dir = sys_get_temp_dir() . '/git command builder test ' . uniqid();
        mkdir($dir);

        try {
            // Build a trivial command using the same `cd <path> && ...` shape as commit()/push(),
            // to prove escapeshellarg() makes the shell treat the space-containing path as one
            // argument rather than splitting on the space (which is exactly what broke before this
            // class existed -- see GitCommitIntegrationTest for the end-to-end regression case).
            $command = sprintf('cd %s && pwd', escapeshellarg($dir));

            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode);
            $this->assertSame(realpath($dir), trim($output[0] ?? ''));
        } finally {
            rmdir($dir);
        }
    }

    public function test_commit_command_quotes_each_argument(): void
    {
        $command = GitCommandBuilder::commit('/data/repo', 'git', 'a user', 'a@b.com', 'a message with spaces');

        $this->assertStringContainsString(escapeshellarg('/data/repo'), $command);
        $this->assertStringContainsString(escapeshellarg('user.name=a user'), $command);
        $this->assertStringContainsString(escapeshellarg('a message with spaces'), $command);
    }

    public function test_push_command_url_encodes_and_quotes_credentials(): void
    {
        $command = GitCommandBuilder::push(
            '/data/repo',
            'git',
            'https://git.example.org/group/repo.git',
            'user@example.com',
            'a/token:with@special-chars',
            'main',
        );

        $this->assertStringContainsString(
            'https://user%40example.com:a%2Ftoken%3Awith%40special-chars@git.example.org/group/repo.git',
            $command
        );
        $this->assertStringStartsWith("cd " . escapeshellarg('/data/repo'), $command);
    }

    public function test_push_with_a_branch_uses_an_explicit_refspec(): void
    {
        $command = GitCommandBuilder::push(
            '/data/repo',
            'git',
            'https://git.example.org/group/repo.git',
            'user',
            'token',
            'main',
        );

        $this->assertStringEndsWith(
            escapeshellarg('https://user:token@git.example.org/group/repo.git') . ' ' . escapeshellarg('main') . ':' . escapeshellarg('main'),
            $command
        );
    }

    public function test_clone_command_has_no_cd_and_quotes_each_argument(): void
    {
        $command = GitCommandBuilder::clone('/repos/a project', 'git', 'https://user:token@git.example.org/group/repo.git');

        $this->assertStringStartsWith(escapeshellarg('git') . ' clone ', $command);
        $this->assertStringNotContainsString('cd ', $command);
        $this->assertStringContainsString(escapeshellarg('https://user:token@git.example.org/group/repo.git'), $command);
        $this->assertStringEndsWith(escapeshellarg('/repos/a project'), $command);
    }

    public function test_checkout_command_quotes_the_branch_name(): void
    {
        $command = GitCommandBuilder::checkout('/data/repo', 'git', 'a branch');

        $this->assertStringContainsString(escapeshellarg('a branch'), $command);
        $this->assertStringStartsWith("cd " . escapeshellarg('/data/repo'), $command);
        $this->assertStringContainsString('checkout', $command);
    }

    public function test_ref_exists_command_shape(): void
    {
        $command = GitCommandBuilder::refExists('/data/repo', 'git', 'refs/heads/main');

        $this->assertStringContainsString('show-ref --verify --quiet', $command);
        $this->assertStringContainsString(escapeshellarg('refs/heads/main'), $command);
    }

    public function test_authenticated_url_encodes_and_quotes_credentials(): void
    {
        $url = GitCommandBuilder::authenticatedUrl(
            'https://git.example.org/group/repo.git',
            'user@example.com',
            'a/token:with@special-chars',
        );

        $this->assertSame(
            'https://user%40example.com:a%2Ftoken%3Awith%40special-chars@git.example.org/group/repo.git',
            $url
        );
    }

    public function test_ls_remote_heads_command_shape(): void
    {
        $command = GitCommandBuilder::lsRemoteHeads('/data/repo', 'git', 'https://user:token@git.example.org/group/repo.git');

        $this->assertStringContainsString('ls-remote --heads', $command);
        $this->assertStringContainsString(escapeshellarg('https://user:token@git.example.org/group/repo.git'), $command);
    }

    public function test_fetch_command_shape(): void
    {
        $command = GitCommandBuilder::fetch('/data/repo', 'git', 'https://url', 'a branch', 'refs/ediarum/incoming');

        $this->assertStringContainsString('fetch', $command);
        $this->assertStringContainsString(escapeshellarg('https://url'), $command);
        // Force-updating (`+`) refspec, as a single argument -- a `+` split across the branch/dest
        // arguments would be parsed by git as a literal `+` in the destination ref's name instead of
        // a force flag on the refspec.
        $this->assertStringContainsString(escapeshellarg('+a branch:refs/ediarum/incoming'), $command);
    }

    public function test_rev_parse_command_shape(): void
    {
        $command = GitCommandBuilder::revParse('/data/repo', 'git', 'refs/heads/main');

        $this->assertStringContainsString('rev-parse', $command);
        $this->assertStringContainsString(escapeshellarg('refs/heads/main'), $command);
    }

    public function test_is_ancestor_command_shape(): void
    {
        $command = GitCommandBuilder::isAncestor('/data/repo', 'git', 'a', 'b');

        $this->assertStringContainsString('merge-base --is-ancestor', $command);
        $this->assertStringContainsString(escapeshellarg('a') . ' ' . escapeshellarg('b'), $command);
    }

    public function test_rev_list_count_command_shape(): void
    {
        $command = GitCommandBuilder::revListCount('/data/repo', 'git', 'a', 'b');

        $this->assertStringContainsString('rev-list --count', $command);
        $this->assertStringContainsString(escapeshellarg('a') . '..' . escapeshellarg('b'), $command);
    }

    public function test_diff_name_status_command_shape(): void
    {
        $command = GitCommandBuilder::diffNameStatus('/data/repo', 'git', 'a', 'b');

        $this->assertStringContainsString('diff --name-status', $command);
        $this->assertStringContainsString(escapeshellarg('a') . ' ' . escapeshellarg('b'), $command);
    }

    public function test_merge_fast_forward_only_command_shape(): void
    {
        $command = GitCommandBuilder::mergeFastForwardOnly('/data/repo', 'git', 'refs/ediarum/incoming');

        $this->assertStringContainsString('merge --ff-only', $command);
        $this->assertStringContainsString(escapeshellarg('refs/ediarum/incoming'), $command);
    }

    public function test_delete_ref_command_shape(): void
    {
        $command = GitCommandBuilder::deleteRef('/data/repo', 'git', 'refs/ediarum/incoming');

        $this->assertStringContainsString('update-ref -d', $command);
        $this->assertStringContainsString(escapeshellarg('refs/ediarum/incoming'), $command);
    }
}
