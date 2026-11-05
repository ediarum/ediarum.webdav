<?php

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/auth/service/projects -- the project registry for app backends (ediarum.platform
 * docs/auth-contract.md, "Service side").
 */
class ServiceProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-service-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.platform.service_token' => self::TOKEN]);
    }

    private function fetch(?string $token = self::TOKEN)
    {
        return $this->getJson('/api/auth/service/projects', $token === null ? [] : ['Authorization' => "Bearer $token"]);
    }

    public function test_it_lists_all_projects_sorted_by_slug_with_exactly_the_contract_fields(): void
    {
        Project::factory()->create([
            'slug' => 'zeta',
            'name' => 'Zeta',
            'git_remote_url' => 'https://git.example.org/group/zeta.git',
            'git_branch' => 'main',
            'is_index_app_source_of_truth' => true,
        ]);
        Project::factory()->create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'git_remote_url' => 'https://git.example.org/group/alpha.git',
            'git_branch' => 'develop',
        ]);

        $this->fetch()->assertOk()->assertExactJson([
            [
                'slug' => 'alpha',
                'name' => 'Alpha',
                'repo_url' => 'https://git.example.org/group/alpha.git',
                'branch' => 'develop',
                'index_source_of_truth' => 'xml',
            ],
            [
                'slug' => 'zeta',
                'name' => 'Zeta',
                'repo_url' => 'https://git.example.org/group/zeta.git',
                'branch' => 'main',
                'index_source_of_truth' => 'app',
            ],
        ]);
    }

    public function test_it_is_not_filtered_by_membership(): void
    {
        $member = User::factory()->create();
        $mine = Project::factory()->create();
        $mine->users()->attach($member);
        $other = Project::factory()->create();

        $this->actingAs($member)->fetch()->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['slug' => $mine->slug])
            ->assertJsonFragment(['slug' => $other->slug]);
    }

    public function test_credentials_never_appear(): void
    {
        Project::factory()->create([
            'git_remote_url' => 'https://someone:glpat-secret@git.example.org/group/p.git',
            'git_username' => 'someone',
            'git_access_token' => 'glpat-other-secret',
            'exist_username' => 'exist-user',
            'exist_password' => 'exist-secret',
        ]);

        $response = $this->fetch()->assertOk();

        $response->assertJsonPath('0.repo_url', 'https://git.example.org/group/p.git');
        foreach (['glpat-secret', 'glpat-other-secret', 'someone', 'exist-user', 'exist-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_without_a_token_it_is_a_401(): void
    {
        $this->fetch(null)->assertStatus(401);
    }

    public function test_with_a_wrong_token_it_is_a_401(): void
    {
        $this->fetch('wrong')->assertStatus(401);
    }

    public function test_a_session_without_the_token_is_a_401(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->fetch(null)->assertStatus(401);
    }

    public function test_without_a_configured_token_it_is_off(): void
    {
        config(['services.platform.service_token' => null]);

        $this->fetch('')->assertStatus(401);
        $this->fetch(null)->assertStatus(401);
    }
}
