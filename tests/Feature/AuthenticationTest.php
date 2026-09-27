<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('AFTE TERMINAL');
        $response->assertSee('Trader Email');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'email' => 'trader@autotrade.io',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'trader@autotrade.io',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'trader@autotrade.io',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'trader@autotrade.io',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');

        $historyResponse = $this->get('/history');
        $historyResponse->assertRedirect('/login');

        $apiResponse = $this->getJson('/api/stats');
        $apiResponse->assertStatus(401);
    }

    public function test_login_screen_does_not_contain_register_option(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertDontSee('Register New Trader');
        $response->assertDontSee('Need an account?');
        $response->assertDontSee(route('register'));
        $response->assertSee('asset/logo.png');
    }

    public function test_guests_cannot_access_register_page(): void
    {
        $response = $this->get('/register');

        $response->assertRedirect('/login');
    }

    public function test_guests_cannot_post_to_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseMissing('users', ['email' => 'intruder@test.com']);
    }

    public function test_non_admin_cannot_access_register_page(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->get('/register');
        $response->assertStatus(403);
    }

    public function test_admin_can_access_register_page_and_register_new_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $getRegister = $this->actingAs($admin)->get('/register');
        $getRegister->assertStatus(200);
        $getRegister->assertSee('REGISTER USER');
        $getRegister->assertSee('asset/logo.png');

        $postRegister = $this->actingAs($admin)->post('/register', [
            'name' => 'New Trader',
            'email' => 'newtrader@example.com',
            'role' => 'user',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $postRegister->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('users', [
            'email' => 'newtrader@example.com',
            'role' => 'user',
        ]);
    }
}
