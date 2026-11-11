<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson(route('users.index'))->assertUnauthorized();
    }

    public function test_user_can_view_own_profile_without_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson(route('users.show', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_user_cannot_view_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson(route('users.show', $other))
            ->assertForbidden();
    }

    public function test_admin_can_view_any_user(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson(route('users.show', $other))
            ->assertOk()
            ->assertJsonPath('data.email', $other->email);
    }

    public function test_email_must_be_valid(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('users.store'), [
                'name' => 'Ivan',
                'email' => 'not-an-email',
                'password' => 'secret123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_admin_can_create_user_and_password_is_hashed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('users.store'), [
                'name' => 'Ivan',
                'email' => 'ivan@example.com',
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ivan@example.com')
            ->assertJsonMissingPath('data.password');

        $fresh = User::whereEmail('ivan@example.com')->sole();

        $this->assertNotSame('secret123', $fresh->password);
        $this->assertTrue(Hash::check('secret123', $fresh->password));
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('users.store'), [
                'name' => 'Ivan',
                'email' => 'ivan@example.com',
                'password' => 'secret123',
            ])
            ->assertForbidden();

        $this->assertSame(0, User::whereEmail('ivan@example.com')->count());
    }

    public function test_is_admin_cannot_be_escalated_through_the_api(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('users.store'), [
                'name' => 'Sneaky',
                'email' => 'sneaky@example.com',
                'password' => 'secret123',
                'is_admin' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_admin', false);

        $this->assertFalse(User::whereEmail('sneaky@example.com')->sole()->is_admin);
    }

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson(route('users.update', $user), ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_user_cannot_update_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson(route('users.update', $other), ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_admin_can_delete_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $victim = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson(route('users.destroy', $victim))
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson(route('users.destroy', $admin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_non_admin_cannot_delete_anyone(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson(route('users.destroy', $other))
            ->assertForbidden();
    }
}
