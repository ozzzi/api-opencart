<?php

declare(strict_types=1);

namespace App\Services\Chat;

use Illuminate\Redis\Connections\Connection;

/**
 * Redis-backed idempotency cache for POST /api/chat/leads.
 *
 * Client-side double-click prevention doesn't cover a request retried after
 * a dropped connection. When the widget sends X-Idempotency-Key, the first
 * response for a given (session, key) pair is cached for 24h and replayed
 * verbatim on retry instead of creating a second lead.
 */
final class LeadIdempotencyGuard
{
    private const int TTL_SECONDS = 86400;

    public function __construct(private readonly Connection $redis)
    {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function find(string $sessionId, string $idempotencyKey): ?array
    {
        $value = $this->redis->get($this->key($sessionId, $idempotencyKey));

        if ($value === null) {
            return null;
        }

        return json_decode((string) $value, true);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function remember(string $sessionId, string $idempotencyKey, int $status, array $body): void
    {
        $this->redis->setex(
            $this->key($sessionId, $idempotencyKey),
            self::TTL_SECONDS,
            json_encode(['status' => $status, 'body' => $body]),
        );
    }

    private function key(string $sessionId, string $idempotencyKey): string
    {
        return "chat:lead-idem:{$sessionId}:{$idempotencyKey}";
    }
}
