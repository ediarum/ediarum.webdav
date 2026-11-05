<?php

namespace Tests\Unit\App\Models;

use App\Models\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectRemoteCommitUrlTest extends TestCase
{
    public static function remotes(): array
    {
        return [
            'https with .git' => ['https://gitlab.example.com/group/repo.git', 'https://gitlab.example.com/group/repo/-/commit/abc123'],
            'https without .git' => ['https://gitlab.example.com/group/sub/repo', 'https://gitlab.example.com/group/sub/repo/-/commit/abc123'],
            'trailing slash' => ['https://gitlab.example.com/group/repo/', 'https://gitlab.example.com/group/repo/-/commit/abc123'],
            'embedded credentials' => ['https://bot:secret@gitlab.example.com/group/repo.git', 'https://gitlab.example.com/group/repo/-/commit/abc123'],
            'https port kept' => ['https://gitlab.example.com:8443/group/repo.git', 'https://gitlab.example.com:8443/group/repo/-/commit/abc123'],
            'github https' => ['https://github.com/org/repo.git', 'https://github.com/org/repo/commit/abc123'],
            'github scp-style ssh' => ['git@github.com:org/repo.git', 'https://github.com/org/repo/commit/abc123'],
            'scp-style ssh' => ['git@gitlab.example.com:group/repo.git', 'https://gitlab.example.com/group/repo/-/commit/abc123'],
            'ssh url, port dropped' => ['ssh://git@gitlab.example.com:2222/group/repo.git', 'https://gitlab.example.com/group/repo/-/commit/abc123'],
        ];
    }

    #[DataProvider('remotes')]
    public function test_it_builds_the_commit_page_url_from_the_remote(string $remote, string $expected): void
    {
        $project = new Project(['git_remote_url' => $remote]);

        $this->assertSame($expected, $project->remoteCommitUrl('abc123'));
    }

    public function test_there_is_no_url_without_a_remote(): void
    {
        $this->assertNull((new Project(['git_remote_url' => null]))->remoteCommitUrl('abc123'));
        $this->assertNull((new Project(['git_remote_url' => '']))->remoteCommitUrl('abc123'));
    }
}
