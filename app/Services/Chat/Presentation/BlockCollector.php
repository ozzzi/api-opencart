<?php

declare(strict_types=1);

namespace App\Services\Chat\Presentation;

use App\Services\Chat\DTO\Blocks\BlockInterface;

/**
 * Sink through which presentational tools hand blocks back to the orchestrator.
 *
 * Tools return a JSON string to the model, so they need a side channel for the
 * typed block the widget renders. Tools push during ToolRegistry::execute(); the
 * orchestrator drains immediately after each execute() call, which is what keeps
 * blocks interleaved with prose in the order they were produced.
 *
 * Bound as a singleton on purpose. ToolRegistry is itself a singleton and resolves
 * every tool instance in its constructor, so tools hold whichever collector they
 * were built with for the worker's lifetime. A `scoped` binding would be flushed
 * between requests under Octane while the tools kept the stale instance, and
 * drain() would silently return nothing in production only. Correctness comes from
 * the explicit reset() at the start of each run, not from the container lifetime.
 */
final class BlockCollector
{
    /** @var list<BlockInterface> */
    private array $blocks = [];

    public function push(BlockInterface $block): void
    {
        $this->blocks[] = $block;
    }

    /**
     * Returns everything collected so far and clears the buffer.
     *
     * Draining is the only way to read, so a block cannot be emitted twice.
     *
     * @return list<BlockInterface>
     */
    public function drain(): array
    {
        $blocks = $this->blocks;
        $this->blocks = [];

        return $blocks;
    }

    /** Discards anything left over by a run that failed mid-loop. */
    public function reset(): void
    {
        $this->blocks = [];
    }
}
