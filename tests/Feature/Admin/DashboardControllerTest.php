<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Bot\AdminUser;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Models\Bot\DailyUsageStat;
use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\CostTrackerInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

final class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CostTrackerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDailyCost')->andReturn(0.0);
        });
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = AdminUser::factory()->admin()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard.index');
    }

    public function test_dashboard_renders_for_manager(): void
    {
        $manager = AdminUser::factory()->create(['role' => 'manager']);

        $this->actingAs($manager, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_dashboard_passes_today_stats_to_view(): void
    {
        $admin = AdminUser::factory()->admin()->create();

        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user']);
        Lead::factory()->create(['session_id' => $session->id]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $response->assertViewHas('todayMessages', 1);
        $response->assertViewHas('todayLeads', 1);
    }

    public function test_dashboard_passes_7day_history_to_view(): void
    {
        $admin = AdminUser::factory()->admin()->create();

        DailyUsageStat::create([
            'date'            => now()->subDays(2)->toDateString(),
            'total_sessions'  => 5,
            'total_messages'  => 20,
            'total_cost_usd'  => 0.15,
            'avg_latency_ms'  => 1200,
            'escalations'     => 3,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $response->assertViewHas('labels');
        $response->assertViewHas('messagesData');
        $response->assertViewHas('costData');
        $response->assertViewHas('latencyData');

        $labels = $response->viewData('labels');
        $this->assertCount(7, $labels);

        $messagesData = $response->viewData('messagesData');
        $this->assertCount(7, $messagesData);
        $this->assertContains(20, $messagesData);
    }

    public function test_dashboard_includes_chart_scripts(): void
    {
        $admin = AdminUser::factory()->admin()->create();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'));

        $response->assertSee('chart-messages');
        $response->assertSee('chart-cost');
        $response->assertSee('chart-latency');
        $response->assertSee('AdminCharts.make');
    }
}
