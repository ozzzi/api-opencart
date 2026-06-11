<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat;

use App\Jobs\SendLeadNotificationJob;
use App\Models\Bot\ChatSession;
use App\Models\Bot\Lead;
use App\Services\Chat\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ReflectionClass;

final class LeadServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_persists_lead_with_all_fields(): void
    {
        Queue::fake();
        $session = ChatSession::factory()->create();

        $lead = $this->makeService()->create($session->id, [
            'name'        => 'Олег',
            'phone'       => '+380991234567',
            'email'       => 'oleg@example.com',
            'message'     => 'Хочу заказать',
            'product_ids' => [1, 2],
        ]);

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertDatabaseHas('leads', [
            'session_id' => $session->id,
            'name'       => 'Олег',
            'phone'      => '+380991234567',
            'email'      => 'oleg@example.com',
            'message'    => 'Хочу заказать',
            'status'     => 'new',
        ]);
        $this->assertSame([1, 2], $lead->product_ids);
    }

    public function test_create_allows_null_optional_fields(): void
    {
        Queue::fake();
        $session = ChatSession::factory()->create();

        $lead = $this->makeService()->create($session->id, ['phone' => '+380991234567']);

        $this->assertNull($lead->name);
        $this->assertNull($lead->email);
        $this->assertNull($lead->message);
        $this->assertNull($lead->product_ids);
    }

    // ── notification dispatch ─────────────────────────────────────────────────

    public function test_dispatches_single_notification_job(): void
    {
        Queue::fake();
        $session = ChatSession::factory()->create();

        $lead = $this->makeService()->create($session->id, ['phone' => '+380991234567']);

        Queue::assertPushedTimes(SendLeadNotificationJob::class, 1);
        Queue::assertPushed(SendLeadNotificationJob::class, function ($job) use ($lead): bool {
            $reflection = new ReflectionClass($job);

            return $reflection->getProperty('leadId')->getValue($job) === $lead->id;
        });
    }

    public function test_notification_job_is_on_notifications_queue(): void
    {
        Queue::fake();
        $session = ChatSession::factory()->create();

        $this->makeService()->create($session->id, ['email' => 'user@example.com']);

        Queue::assertPushed(SendLeadNotificationJob::class, fn ($job) => $job->queue === 'notifications');
    }

    // ── updateStatus ──────────────────────────────────────────────────────────

    public function test_update_status_changes_lead_status(): void
    {
        $lead = Lead::factory()->create(['status' => 'new']);

        $this->makeService()->updateStatus($lead->id, 'contacted');

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'contacted']);
    }

    public function test_update_status_throws_when_lead_not_found(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->makeService()->updateStatus(99999, 'closed');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeService(): LeadService
    {
        return new LeadService();
    }
}
