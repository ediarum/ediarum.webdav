<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_users_by_name(): void
    {
        $admin = User::factory()->create(['name' => 'Zoe Admin', 'is_admin' => true]);
        $editor = User::factory()->create(['name' => 'Anna Editor', 'is_admin' => false]);

        $response = $this->actingAs($admin)->getJson(route('users.index'));

        $response->assertOk();
        $response->assertExactJson([
            ['id' => $editor->id, 'name' => 'Anna Editor', 'email' => $editor->email, 'isAdmin' => false],
            ['id' => $admin->id, 'name' => 'Zoe Admin', 'email' => $admin->email, 'isAdmin' => true],
        ]);
    }

    public function test_store_creates_a_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->postJson(route('users.store'), [
            'name' => 'New Editor',
            'email' => 'new@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $response->assertExactJson(['id' => $user->id]);
        $this->assertSame('New Editor', $user->name);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertTrue(Hash::check('secret-password-1', $user->password));
    }

    public function test_store_can_create_an_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson(route('users.store'), [
            'name' => 'Second Admin',
            'email' => 'admin2@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
            'is_admin' => true,
        ])->assertCreated();

        $this->assertTrue((bool) User::where('email', 'admin2@example.com')->value('is_admin'));
    }

    public function test_store_rejects_a_duplicate_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $existing = User::factory()->create();

        $response = $this->actingAs($admin)->postJson(route('users.store'), [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_a_non_admin_cannot_create_a_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->postJson(route('users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_reset_password_returns_the_new_password_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $editor = User::factory()->create(['name' => 'Forgetful Editor']);

        $response = $this->actingAs($admin)->putJson(route('users.reset-password', ['id' => $editor->id]));

        $response->assertOk();
        $response->assertJsonPath('name', 'Forgetful Editor');
        $password = $response->json('password');
        $this->assertSame(10, strlen($password));
        $this->assertTrue(Hash::check($password, $editor->fresh()->password));
    }

    public function test_reset_password_of_an_unknown_user_is_a_404(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->putJson(route('users.reset-password', ['id' => 999999]))->assertNotFound();
    }

    public function test_a_non_admin_cannot_reset_a_password(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create();

        $this->actingAs($user)->putJson(route('users.reset-password', ['id' => $other->id]))->assertForbidden();

        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }
}
