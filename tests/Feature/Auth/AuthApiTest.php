<?php

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The auth provider side of the platform (/api/auth/…) -- the spec is ediarum.platform's
 * docs/auth-contract.md.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_sets_the_xsrf_token_cookie(): void
    {
        $response = $this->get('/api/auth/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_login_returns_the_user_with_roles(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertExactJson(['name' => $admin->name, 'email' => $admin->email, 'roles' => ['admin'], 'projects' => []]);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_non_admin_logs_in_without_roles(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('roles', []);
    }

    public function test_login_with_a_wrong_password_is_a_422(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/logout');

        $response->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_logout_without_a_session_is_still_a_204(): void
    {
        $this->postJson('/api/auth/logout')->assertNoContent();
    }

    public function test_me_returns_the_logged_in_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertExactJson(['name' => $user->name, 'email' => $user->email, 'roles' => [], 'projects' => []]);
    }

    public function test_me_without_a_session_is_a_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_requires_confirmation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_password_change_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertNoContent();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_change_without_a_session_is_a_401(): void
    {
        $this->postJson('/api/auth/password', [])->assertUnauthorized();
    }

    public function test_a_user_can_change_their_own_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'is_admin' => false]);

        $response = $this->actingAs($user)->postJson('/api/auth/profile', ['name' => '  New Name  ']);

        $response->assertOk();
        $response->assertExactJson(['name' => 'New Name', 'email' => $user->email, 'roles' => [], 'projects' => []]);
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_the_email_cannot_be_changed_through_the_profile(): void
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)->postJson('/api/auth/profile', [
            'name' => 'Still Me',
            'email' => 'someone-else@example.com',
        ])->assertOk()->assertJsonPath('email', $email);

        $this->assertSame($email, $user->fresh()->email);
    }

    public function test_the_name_is_required_and_limited(): void
    {
        $user = User::factory()->create(['name' => 'Kept']);

        foreach (['', '   ', str_repeat('x', 256)] as $name) {
            $this->actingAs($user)->postJson('/api/auth/profile', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame('Kept', $user->fresh()->name);
    }

    public function test_profile_without_a_session_is_a_401(): void
    {
        $this->postJson('/api/auth/profile', ['name' => 'Nobody'])->assertUnauthorized();
    }

    public function test_verify_reports_the_user_and_roles_in_headers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/api/auth/verify');

        $response->assertNoContent();
        $response->assertHeader('X-User', $admin->email);
        $response->assertHeader('X-User-Roles', 'admin');
    }

    public function test_verify_sends_an_empty_roles_header_for_a_non_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/api/auth/verify');

        $response->assertNoContent();
        $response->assertHeader('X-User', $user->email);
        $response->assertHeader('X-User-Roles', '');
        $response->assertHeader('X-User-Projects', '');
    }

    /**
     * nginx `auth_request` sends neither Accept nor Origin -- it must still get a plain 401, not a
     * redirect to a login page.
     */
    public function test_verify_without_a_session_is_a_401(): void
    {
        $response = $this->get('/api/auth/verify');

        $response->assertUnauthorized();
        $response->assertHeaderMissing('X-User');
    }

    /**
     * The XSRF-TOKEN cookie exactly as the browser holds it (encrypted), which is what the browser
     * echoes as X-XSRF-TOKEN and what an app backend forwards to verify.
     */
    private function xsrfCookieFor(User $user): string
    {
        $cookie = $this->actingAs($user)->get('/api/auth/csrf-cookie')->getCookie('XSRF-TOKEN', false);

        return $cookie->getValue();
    }

    public function test_verify_accepts_a_write_with_a_valid_xsrf_token(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $token = $this->xsrfCookieFor($user);

        $response = $this->actingAs($user)->get('/api/auth/verify', [
            'X-Original-Method' => 'POST',
            'X-XSRF-TOKEN' => $token,
        ]);

        $response->assertNoContent();
        $response->assertHeader('X-User', $user->email);
        $response->assertHeader('X-User-Roles', 'admin');
    }

    public function test_verify_rejects_a_write_without_an_xsrf_token_with_419(): void
    {
        $user = User::factory()->create();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'post'] as $method) {
            $response = $this->actingAs($user)->get('/api/auth/verify', ['X-Original-Method' => $method]);

            $response->assertStatus(419);
            $response->assertHeaderMissing('X-User');
        }
    }

    public function test_verify_rejects_a_write_with_a_wrong_xsrf_token_with_419(): void
    {
        $user = User::factory()->create();
        $this->xsrfCookieFor($user);

        foreach (['not-encrypted', encrypt('wrong-token', false)] as $header) {
            $this->actingAs($user)->get('/api/auth/verify', [
                'X-Original-Method' => 'DELETE',
                'X-XSRF-TOKEN' => $header,
            ])->assertStatus(419);
        }
    }

    public function test_verify_needs_no_xsrf_token_for_safe_methods_or_a_missing_method(): void
    {
        $user = User::factory()->create();

        foreach (['GET', 'HEAD', 'OPTIONS', 'get', null] as $method) {
            $headers = $method === null ? [] : ['X-Original-Method' => $method];

            $this->actingAs($user)->get('/api/auth/verify', $headers)
                ->assertNoContent()
                ->assertHeader('X-User', $user->email);
        }
    }

    public function test_verify_without_a_session_is_a_401_also_for_writes(): void
    {
        $this->get('/api/auth/verify', ['X-Original-Method' => 'POST'])->assertUnauthorized();
    }

    public function test_verify_itself_needs_no_csrf_token_or_origin(): void
    {
        $user = User::factory()->create();

        // Called server to server: no Origin, no Referer, no X-XSRF-TOKEN for a GET.
        $this->actingAs($user)
            ->call('GET', '/api/auth/verify', server: ['HTTP_ACCEPT' => '*/*'])
            ->assertNoContent();
    }

    // --- Projects (docs/auth-contract.md, "Projects") ---

    public function test_login_me_and_profile_report_the_users_project_slugs(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->projects()->attach([
            Project::factory()->create(['slug' => 'schleiermacher'])->id,
            Project::factory()->create(['slug' => 'humboldt'])->id,
        ]);
        Project::factory()->create(['slug' => 'not-mine']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('projects', ['humboldt', 'schleiermacher']);
        $this->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('projects', ['humboldt', 'schleiermacher']);
        $this->postJson('/api/auth/profile', ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('projects', ['humboldt', 'schleiermacher']);
    }

    /**
     * `projects` is membership only: admins may access every project, but that comes from their
     * role, not from extra slugs.
     */
    public function test_an_admin_without_memberships_reports_no_project_slugs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create(['slug' => 'schleiermacher']);

        $this->actingAs($admin)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('roles', ['admin'])->assertJsonPath('projects', []);

        $this->actingAs($admin)->get('/api/auth/verify')
            ->assertNoContent()
            ->assertHeader('X-User-Roles', 'admin')
            ->assertHeader('X-User-Projects', '');
    }

    public function test_verify_sends_the_project_slugs_comma_separated(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->projects()->attach([
            Project::factory()->create(['slug' => 'schleiermacher'])->id,
            Project::factory()->create(['slug' => 'humboldt'])->id,
        ]);

        $this->actingAs($user)->get('/api/auth/verify')
            ->assertNoContent()
            ->assertHeader('X-User', $user->email)
            ->assertHeader('X-User-Roles', '')
            ->assertHeader('X-User-Projects', 'humboldt,schleiermacher');
    }

    public function test_verify_sends_a_single_project_without_a_comma(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->projects()->attach(Project::factory()->create(['slug' => 'humboldt'])->id);

        $this->actingAs($user)->get('/api/auth/verify')->assertHeader('X-User-Projects', 'humboldt');
    }

    public function test_projects_lists_a_members_own_projects_sorted_by_name(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->projects()->attach([
            Project::factory()->create(['slug' => 'zeta', 'name' => 'Alpha Edition'])->id,
            Project::factory()->create(['slug' => 'alpha', 'name' => 'Zeta Edition'])->id,
        ]);
        Project::factory()->create(['slug' => 'not-mine', 'name' => 'Middle Edition']);

        $this->actingAs($user)->getJson('/api/auth/projects')
            ->assertOk()
            ->assertExactJson([
                ['slug' => 'zeta', 'name' => 'Alpha Edition'],
                ['slug' => 'alpha', 'name' => 'Zeta Edition'],
            ]);
    }

    public function test_projects_is_empty_for_a_non_member(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Project::factory()->create(['slug' => 'schleiermacher']);

        $this->actingAs($user)->getJson('/api/auth/projects')->assertOk()->assertExactJson([]);
    }

    public function test_projects_lists_all_projects_for_an_admin_without_memberships(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Project::factory()->create(['slug' => 'b', 'name' => 'Beta']);
        Project::factory()->create(['slug' => 'a', 'name' => 'Alpha']);

        $this->actingAs($admin)->getJson('/api/auth/projects')
            ->assertOk()
            ->assertExactJson([
                ['slug' => 'a', 'name' => 'Alpha'],
                ['slug' => 'b', 'name' => 'Beta'],
            ]);
    }

    public function test_projects_without_a_session_is_a_401(): void
    {
        $this->getJson('/api/auth/projects')->assertUnauthorized();
        $this->get('/api/auth/projects')->assertUnauthorized();
    }
}
