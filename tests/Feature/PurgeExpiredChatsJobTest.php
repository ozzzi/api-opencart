<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PurgeExpiredChatsJob;
use App\Models\Bot\ChatSession;
use App\Models\Bot\Lead;
use App\Settings\BotPrivacySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PurgeExpiredChatsJobTest extends TestCase
{
    use RefreshDatabase;

    // ── deletion ──────────────────────────────────────────────────────────────

    public function test_expired_session_without_lead_is_deleted(): void
    {
        $this->setRetentionDays(30);

        $session = ChatSession::factory()->create([
            'created_at' => now()->subDays(31),
        ]);

        (new PurgeExpiredChatsJob)->handle(app(BotPrivacySettings::class));

        $this->assertModelMissing($session);
    }

    public function test_recent_session_without_lead_is_not_deleted(): void
    {
        $this->setRetentionDays(30);

        $session = ChatSession::factory()->create([
            'created_at' => now()->subDays(10),
        ]);

        (new PurgeExpiredChatsJob)->handle(app(BotPrivacySettings::class));

        $this->assertModelExists($session);
    }

    // ── anonymisation ─────────────────────────────────────────────────────────

    public function test_expired_session_with_lead_is_anonymised_not_deleted(): void
    {
        $this->setRetentionDays(30);

        $session = ChatSession::factory()->create([
            'ip_address' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subDays(31),
        ]);
        Lead::factory()->create(['session_id' => $session->id]);

        (new PurgeExpiredChatsJob)->handle(app(BotPrivacySettings::class));

        $this->assertModelExists($session);
        $session->refresh();
        $this->assertSame('0.0.0.0', $session->ip_address);
        $this->assertSame('anonymized', $session->user_agent);
    }

    public function test_recent_session_with_lead_is_not_anonymised(): void
    {
        $this->setRetentionDays(30);

        $session = ChatSession::factory()->create([
            'ip_address' => '1.2.3.4',
            'created_at' => now()->subDays(10),
        ]);
        Lead::factory()->create(['session_id' => $session->id]);

        (new PurgeExpiredChatsJob)->handle(app(BotPrivacySettings::class));

        $session->refresh();
        $this->assertSame('1.2.3.4', $session->ip_address);
    }

    // ── gdpr command ─────────────────────────────────────────────────────────

    public function test_gdpr_purge_command_deletes_session_without_lead(): void
    {
        $session = ChatSession::factory()->create();

        $this->artisan('chat:gdpr-purge', ['session_id' => $session->id])
            ->assertSuccessful();

        $this->assertModelMissing($session);
    }

    public function test_gdpr_purge_command_anonymises_session_with_lead(): void
    {
        $session = ChatSession::factory()->create([
            'ip_address' => '5.6.7.8',
            'user_agent' => 'TestAgent',
        ]);
        Lead::factory()->create(['session_id' => $session->id]);

        $this->artisan('chat:gdpr-purge', ['session_id' => $session->id])
            ->assertSuccessful();

        $this->assertModelExists($session);
        $session->refresh();
        $this->assertSame('0.0.0.0', $session->ip_address);
        $this->assertSame('anonymized', $session->user_agent);
    }

    public function test_gdpr_purge_command_fails_for_unknown_session(): void
    {
        $this->artisan('chat:gdpr-purge', ['session_id' => 'non-existent-id'])
            ->assertFailed();
    }

    private function setRetentionDays(int $days): void
    {
        $settings = app(BotPrivacySettings::class);
        $settings->dataRetentionDays = $days;
        $settings->save();
    }
}
