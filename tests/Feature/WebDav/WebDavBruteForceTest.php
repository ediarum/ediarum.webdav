<?php

namespace Tests\Feature\WebDav;

use App\Http\Middleware\ThrottleFailedBasicAuth;
use App\Models\User;

/**
 * Failed Basic-auth logins on the WebDAV endpoint are limited (ThrottleFailedBasicAuth); working
 * clients are not.
 */
class WebDavBruteForceTest extends WebDavTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every failed login takes at least 200 ms (Laravel's Timebox, against timing attacks);
        // these tests fail dozens of times.
        config(['auth.timebox_duration' => 0]);
    }

    public function test_too_many_wrong_passwords_block_the_account_from_that_ip_even_with_the_right_one(): void
    {
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_USER; $i++) {
            $this->propfind($this->user->email, 'wrong')->assertStatus(401);
        }

        $response = $this->propfind($this->user->email, 'password');

        $response->assertStatus(429);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_the_block_ends_after_the_window(): void
    {
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_USER; $i++) {
            $this->propfind($this->user->email, 'wrong');
        }

        $this->travel(ThrottleFailedBasicAuth::DECAY_SECONDS + 1)->seconds();

        $this->propfind($this->user->email, 'password')->assertStatus(207);
    }

    public function test_the_same_account_from_another_ip_is_not_blocked(): void
    {
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_USER; $i++) {
            $this->propfind($this->user->email, 'wrong', '10.0.0.1');
        }

        $this->propfind($this->user->email, 'password', '10.0.0.2')->assertStatus(207);
    }

    public function test_cycling_through_usernames_blocks_the_ip(): void
    {
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_IP; $i++) {
            $this->propfind("guess{$i}@example.org", 'wrong')->assertStatus(401);
        }

        $this->propfind($this->user->email, 'password')->assertStatus(429);
    }

    /** Oxygen's first request carries no credentials; the 401 is the challenge, not a failure. */
    public function test_requests_without_credentials_do_not_count(): void
    {
        $server = $this->transformHeadersToServerVars(['Depth' => '0']);
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_IP + 5; $i++) {
            $this->call('PROPFIND', "/connection/{$this->project->slug}/", [], [], [], $server)->assertStatus(401);
        }

        $this->propfind($this->user->email, 'password')->assertStatus(207);
    }

    public function test_successful_and_forbidden_requests_do_not_count(): void
    {
        $outsider = User::factory()->create();
        for ($i = 0; $i < ThrottleFailedBasicAuth::MAX_PER_IP + 5; $i++) {
            $this->propfind($this->user->email, 'password')->assertStatus(207);
            $this->propfind($outsider->email, 'password')->assertStatus(403);
        }

        $this->propfind($this->user->email, 'password')->assertStatus(207);
    }

    private function propfind(string $email, string $password, string $ip = '127.0.0.1')
    {
        // See WebDavTestCase::webdav(): the basic guard would keep the first authenticated user.
        if ($this->app['auth']->guard()->check()) {
            $this->app['auth']->guard()->logout();
        }
        $this->app['auth']->forgetGuards();

        $server = $this->transformHeadersToServerVars(['Depth' => '0']);
        $server['PHP_AUTH_USER'] = $email;
        $server['PHP_AUTH_PW'] = $password;
        $server['REMOTE_ADDR'] = $ip;

        return $this->call('PROPFIND', "/connection/{$this->project->slug}/", [], [], [], $server);
    }
}
