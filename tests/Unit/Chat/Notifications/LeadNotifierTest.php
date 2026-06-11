<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Notifications;

use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\NotificationChannelInterface;
use App\Services\Chat\Notifications\LeadNotifier;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class LeadNotifierTest extends TestCase
{
    // ── channel delegation ────────────────────────────────────────────────────

    public function test_notify_calls_all_channels(): void
    {
        $lead = Lead::factory()->make(['id' => 1, 'session_id' => null]);

        $channelA = Mockery::mock(NotificationChannelInterface::class);
        $channelB = Mockery::mock(NotificationChannelInterface::class);

        $channelA->expects('send')->once();
        $channelB->expects('send')->once();

        (new LeadNotifier([$channelA, $channelB]))->notify($lead);
    }

    public function test_notify_works_with_no_channels(): void
    {
        $lead = Lead::factory()->make(['session_id' => null]);

        (new LeadNotifier([]))->notify($lead);

        $this->expectNotToPerformAssertions();
    }

    // ── subject formatting ────────────────────────────────────────────────────

    public function test_subject_includes_lead_id_and_name(): void
    {
        $lead = Lead::factory()->make(['id' => 42, 'session_id' => null, 'name' => 'Олег']);

        $this->assertChannelReceivesSubject($lead, fn (string $s) => str_contains($s, '42') && str_contains($s, 'Олег'));
    }

    public function test_subject_falls_back_to_id_when_name_is_null(): void
    {
        $lead = Lead::factory()->make(['id' => 7, 'session_id' => null, 'name' => null]);

        $this->assertChannelReceivesSubject($lead, fn (string $s) => str_contains($s, '7') && ! str_contains($s, ':'));
    }

    // ── body formatting ───────────────────────────────────────────────────────

    public function test_body_contains_phone(): void
    {
        $lead = Lead::factory()->make(['session_id' => null, 'phone' => '+380991234567', 'email' => null]);

        $this->assertChannelReceivesBody($lead, fn (string $b) => str_contains($b, '+380991234567'));
    }

    public function test_body_contains_email(): void
    {
        $lead = Lead::factory()->make(['session_id' => null, 'email' => 'user@example.com', 'phone' => null]);

        $this->assertChannelReceivesBody($lead, fn (string $b) => str_contains($b, 'user@example.com'));
    }

    public function test_body_contains_message(): void
    {
        $lead = Lead::factory()->make(['session_id' => null, 'message' => 'Потрібна консультація']);

        $this->assertChannelReceivesBody($lead, fn (string $b) => str_contains($b, 'Потрібна консультація'));
    }

    public function test_body_contains_product_ids(): void
    {
        $lead = Lead::factory()->make(['session_id' => null, 'product_ids' => [10, 20]]);

        $this->assertChannelReceivesBody($lead, fn (string $b) => str_contains($b, '10') && str_contains($b, '20'));
    }

    public function test_body_omits_null_fields(): void
    {
        $lead = Lead::factory()->make([
            'session_id'  => null,
            'name'        => null,
            'phone'       => '+380991234567',
            'email'       => null,
            'message'     => null,
            'product_ids' => null,
        ]);

        $this->assertChannelReceivesBody($lead, function (string $b): bool {
            return ! str_contains($b, 'Email:')
                && ! str_contains($b, 'Message:')
                && ! str_contains($b, 'Products:');
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function assertChannelReceivesSubject(Lead $lead, callable $assertion): void
    {
        /** @var MockInterface&NotificationChannelInterface $channel */
        $channel = Mockery::mock(NotificationChannelInterface::class);
        $channel->expects('send')
            ->once()
            ->withArgs(function (string $subject) use ($assertion): bool {
                return $assertion($subject);
            });

        (new LeadNotifier([$channel]))->notify($lead);
    }

    private function assertChannelReceivesBody(Lead $lead, callable $assertion): void
    {
        /** @var MockInterface&NotificationChannelInterface $channel */
        $channel = Mockery::mock(NotificationChannelInterface::class);
        $channel->expects('send')
            ->once()
            ->withArgs(function (string $subject, string $body) use ($assertion): bool {
                return $assertion($body);
            });

        (new LeadNotifier([$channel]))->notify($lead);
    }
}
