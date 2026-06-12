<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Bot\AdminUser;
use App\Services\Chat\Contracts\CostTrackerInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

final class AdminAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_page_is_accessible(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertViewIs('admin.auth.login');
    }

    public function test_authenticated_admin_is_redirected_from_login(): void
    {
        $admin = AdminUser::factory()->admin()->create();

        $response = $this->actingAs($admin, 'admin')->get(route('admin.login'));

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = AdminUser::factory()->create([
            'email'    => 'admin@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->post(route('admin.login.post'), [
            'email'    => 'admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_cannot_login_with_wrong_password(): void
    {
        AdminUser::factory()->create(['email' => 'admin@test.com']);

        $response = $this->post(route('admin.login.post'), [
            'email'    => 'admin@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_admin_cannot_login_with_nonexistent_email(): void
    {
        $response = $this->post(route('admin.login.post'), [
            'email'    => 'nobody@test.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->post(route('admin.login.post'), []);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_logout(): void
    {
        $admin = AdminUser::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_manager_role_can_access_dashboard(): void
    {
        $manager = AdminUser::factory()->create(['role' => 'manager']);

        $this->mock(CostTrackerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDailyCost')->andReturn(0.0);
        });

        $response = $this->actingAs($manager, 'admin')->get(route('admin.dashboard'));

        $response->assertOk();
    }

    public function test_admin_role_flag(): void
    {
        $admin = AdminUser::factory()->admin()->create();
        $manager = AdminUser::factory()->create(['role' => 'manager']);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($manager->isAdmin());
    }
}
