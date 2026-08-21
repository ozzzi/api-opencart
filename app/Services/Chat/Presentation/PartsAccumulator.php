<?php

declare(strict_types=1);

namespace App\Services\Chat\Presentation;

use App\Services\Chat\DTO\Blocks\BlockInterface;

/**
 * Builds the ordered `parts` array for one assistant reply
 * (task-structured-output.md §1, §2.2).
 *
 * Prose is buffered; pushing a block flushes the buffer into a text part first, so
 * a block always cuts the prose at the point it actually arrived. The result is the
 * same array that is streamed to the widget and stored on the message, which is
 * what lets history and live stream render through one component.
 *
 * A short-lived builder — constructed per orchestrator run, never injected.
 */
final class PartsAccumulator
{
    /** @var list<array<string, mixed>> */
    private array $parts = [];

    /** @var list<string> */
    private array $textParts = [];

    private string $buffer = '';

    public function appendText(string $text): void
    {
        if ($this->buffer !== '') {
            $this->buffer .= "\n\n";
        }

        $this->buffer .= $text;
    }

    public function pushBlock(BlockInterface $block): void
    {
        $this->flush();

        $this->parts[] = $block->toArray();
    }

    /**
     * Flushes any trailing prose and returns the finished, ordered parts.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): array
    {
        $this->flush();

        return $this->parts;
    }

    /**
     * The text parts joined together — persisted to `chat_messages.content`.
     *
     * Summarization, full-text search and the admin conversation view all want the
     * prose without the blocks, so `content` keeps meaning exactly what it did
     * before parts existed. Read this after finish().
     */
    public function textContent(): string
    {
        return implode("\n\n", $this->textParts);
    }

    private function flush(): void
    {
        $text = mb_trim($this->buffer);
        $this->buffer = '';

        if ($text === '') {
            return;
        }

        $this->parts[] = ['type' => 'text', 'text' => $text];
        $this->textParts[] = $text;
    }
}
