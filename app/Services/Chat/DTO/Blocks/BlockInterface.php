<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/**
 * A typed presentational block in an assistant reply.
 *
 * Blocks are built by the backend from live catalog data, never from model output,
 * so a card physically cannot carry a hallucinated price (task-structured-output.md
 * §0, SO-2). They are streamed as `event: block` and stored in `chat_messages.parts`.
 */
interface BlockInterface
{
    /** Discriminator for the block union, e.g. "products". */
    public function type(): string;

    /**
     * Wire representation, matching task-structured-output.md §3.2 exactly.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
