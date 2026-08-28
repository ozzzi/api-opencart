<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Notifications;

use App\Services\Chat\Contracts\NotificationChannelInterface;
use App\Services\Chat\Notifications\AlertNotifier;
use Mockery;
use Tests\TestCase;

final class AlertNotifierTest extends TestCase
{
    public function test_notify_reaches_every_channel(): void
    {
        $a = $this->channel(enabled: true);
        $b = $this->channel(enabled: true);

        $a->expects('send')->with('Subject', 'Body');
        $b->expects('send')->with('Subject', 'Body');

        $this->notifier($a, $b)->notify('Subject', 'Body');
    }

    public function test_is_enabled_when_any_single_channel_can_deliver(): void
    {
        $this->assertTrue(
            $this->notifier($this->channel(enabled: false), $this->channel(enabled: true))->isEnabled(),
        );
    }

    /**
     * What callers act on: with everything switched off there is no point
     * queueing the work of building an alert.
     */
    public function test_is_not_enabled_when_every_channel_is_off(): void
    {
        $this->assertFalse(
            $this->notifier($this->channel(enabled: false), $this->channel(enabled: false))->isEnabled(),
        );
    }

    public function test_is_not_enabled_without_any_channels(): void
    {
        $this->assertFalse((new AlertNotifier([]))->isEnabled());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function channel(bool $enabled): Mockery\MockInterface
    {
        $channel = Mockery::mock(NotificationChannelInterface::class);
        $channel->allows('isEnabled')->andReturn($enabled);
        $channel->allows('send')->byDefault();

        return $channel;
    }

    private function notifier(Mockery\MockInterface ...$channels): AlertNotifier
    {
        /** @var list<NotificationChannelInterface> $channels */
        return new AlertNotifier($channels);
    }
}
