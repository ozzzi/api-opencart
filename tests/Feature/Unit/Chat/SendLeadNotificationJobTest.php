<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat;

use App\Jobs\SendLeadNotificationJob;
use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\LeadNotifierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SendLeadNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var MockInterface&LeadNotifierInterface */
    private MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = Mockery::mock(LeadNotifierInterface::class);
    }

    // ── handle ────────────────────────────────────────────────────────────────

    public function test_handle_calls_notifier_with_resolved_lead(): void
    {
        $lead = Lead::factory()->create();

        $this->notifier
            ->expects('notify')
            ->once()
            ->withArgs(fn (Lead $l) => $l->id === $lead->id);

        $this->runJob($lead->id);
    }

    public function test_handle_throws_when_lead_not_found(): void
    {
        $this->notifier->expects('notify')->never();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->runJob(99999);
    }

    // ── queue config ──────────────────────────────────────────────────────────

    public function test_job_is_on_notifications_queue(): void
    {
        $job = new SendLeadNotificationJob(1);

        $this->assertSame('notifications', $job->queue);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function runJob(int $leadId): void
    {
        $job = new SendLeadNotificationJob($leadId);
        $job->handle($this->notifier);
    }
}
