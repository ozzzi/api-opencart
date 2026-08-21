<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Presentation;

use App\Services\Chat\DTO\Blocks\ProductsBlock;
use App\Services\Chat\Presentation\BlockCollector;
use Tests\TestCase;

final class BlockCollectorTest extends TestCase
{
    public function test_drain_is_empty_when_nothing_was_pushed(): void
    {
        $this->assertSame([], (new BlockCollector)->drain());
    }

    public function test_drain_returns_pushed_blocks_in_order(): void
    {
        $collector = new BlockCollector;
        $first = new ProductsBlock([]);
        $second = new ProductsBlock([]);

        $collector->push($first);
        $collector->push($second);

        $this->assertSame([$first, $second], $collector->drain());
    }

    public function test_draining_clears_the_buffer_so_a_block_cannot_be_emitted_twice(): void
    {
        $collector = new BlockCollector;
        $collector->push(new ProductsBlock([]));

        $collector->drain();

        $this->assertSame([], $collector->drain());
    }

    public function test_reset_discards_residue_from_a_failed_run(): void
    {
        $collector = new BlockCollector;
        $collector->push(new ProductsBlock([]));

        $collector->reset();

        $this->assertSame([], $collector->drain());
    }
}
