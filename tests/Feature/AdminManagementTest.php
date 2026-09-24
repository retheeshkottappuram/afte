<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_user_management(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->get('/admin/users');
        $response->assertStatus(403);
    }

    public function test_admin_can_access_user_management_and_create_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertStatus(200);
        $response->assertSee('User Management');

        $createResponse = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Alice Trader',
            'email' => 'alice@example.com',
            'role' => 'user',
            'password' => 'Password123!',
        ]);

        $createResponse->assertRedirect('/admin/users');
        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'role' => 'user',
        ]);
    }

    public function test_admin_can_access_role_permissions_matrix_and_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $perm1 = Permission::where('slug', 'view_signals')->firstOrFail();
        $perm2 = Permission::where('slug', 'trigger_scans')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/roles');
        $response->assertStatus(200);
        $response->assertSee('Role &amp; Permission Matrix', false);

        $updateResponse = $this->actingAs($admin)->post('/admin/roles', [
            'permissions' => [$perm1->slug],
        ]);

        $updateResponse->assertRedirect('/admin/roles');
        $this->assertDatabaseHas('role_permissions', [
            'role' => 'user',
            'permission_slug' => $perm1->slug,
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'role' => 'user',
            'permission_slug' => $perm2->slug,
        ]);
    }

    public function test_permission_middleware_denies_unauthorized_user(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        // Revoke all permissions from 'user' role
        DB::table('role_permissions')->where('role', 'user')->delete();

        // User no longer has 'view_signals' permission
        $response = $this->actingAs($user)->get('/signals');
        $response->assertStatus(403);
    }
}
