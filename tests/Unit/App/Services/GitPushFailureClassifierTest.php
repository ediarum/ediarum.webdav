<?php

namespace Tests\Unit\App\Services;

use App\Services\GitPushFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitPushFailureClassifierTest extends TestCase
{
    #[DataProvider('authFailureOutputProvider')]
    public function test_recognized_auth_failure_signatures_classify_as_authentication_failures(string $output): void
    {
        $this->assertTrue(GitPushFailureClassifier::isAuthenticationFailure($output));
    }

    public static function authFailureOutputProvider(): array
    {
        return [
            'libcurl authentication failed' => [
                "remote: HTTP Basic: Access denied\nfatal: Authentication failed for 'https://gitlab.example.com/group/repo.git/'",
            ],
            'gitlab basic auth denied' => [
                "remote: HTTP Basic: Access denied\nremote: You must use a personal access token with 'write_repository' scope for Git over HTTP.\nfatal: unable to access 'https://gitlab.example.com/group/repo.git/': The requested URL returned error: 401",
            ],
            'curl 401' => [
                "fatal: unable to access 'https://gitlab.example.com/group/repo.git/': The requested URL returned error: 401",
            ],
            'curl 403' => [
                "fatal: unable to access 'https://gitlab.example.com/group/repo.git/': The requested URL returned error: 403",
            ],
        ];
    }

    #[DataProvider('nonAuthFailureOutputProvider')]
    public function test_non_authentication_failures_do_not_classify_as_authentication_failures(string $output): void
    {
        $this->assertFalse(GitPushFailureClassifier::isAuthenticationFailure($output));
    }

    public static function nonAuthFailureOutputProvider(): array
    {
        return [
            'non-fast-forward rejection' => [
                "To https://gitlab.example.com/group/repo.git\n ! [rejected]        main -> main (non-fast-forward)\nerror: failed to push some refs to 'https://gitlab.example.com/group/repo.git'",
            ],
            'unresolvable host' => [
                "fatal: unable to access 'https://gitlab.invalid-example.com/group/repo.git/': Could not resolve host: gitlab.invalid-example.com",
            ],
            'repository not found' => [
                "remote: The project you were looking for could not be found or you don't have permission to view it.\nfatal: repository 'https://gitlab.example.com/group/missing-repo.git/' not found",
            ],
        ];
    }
}
