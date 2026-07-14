<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\ChatSessionToken;
use App\Http\Middleware\TokenAuth;
use App\Models\Bot\ChatSession;
use App\Settings\BotChatSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

final class ChatSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $settings->sessionTtlMinutes = 60;
        $this->app->instance(BotChatSettings::class, $settings);

        $this->withoutMiddleware(TokenAuth::class);

        Route::middleware(ChatSessionToken::class)
            ->get('/_test/chat-session', fn () => response()->json(['ok' => true]));
    }

    public function test_missing_header_returns_401(): void
    {
        $response = $this->getJson('/_test/chat-session');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Missing session token.']);
    }

    public function test_unknown_session_id_returns_401(): void
    {
        $response = $this->hitProtectedRoute('00000000-0000-0000-0000-000000000000');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid or expired session.']);
    }

    public function test_expired_session_returns_401(): void
    {
        $session = ChatSession::factory()->create([
            'last_activity_at' => Carbon::now()->subDays(2),
        ]);

        $response = $this->hitProtectedRoute($session->id);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid or expired session.']);
    }

    public function test_valid_session_passes_through(): void
    {
        $session = ChatSession::factory()->create([
            'last_activity_at' => now(),
        ]);

        $response = $this->hitProtectedRoute($session->id);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_session_id_with_leading_zero_is_not_truncated(): void
    {
        $session = ChatSession::factory()->create([
            'id' => '00000000-1234-4abc-8def-0123456789ab',
            'last_activity_at' => now(),
        ]);

        $response = $this->hitProtectedRoute($session->id);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    private function hitProtectedRoute(string $sessionId = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/_test/chat-session', ['X-Chat-Session' => $sessionId]);
    }
}
