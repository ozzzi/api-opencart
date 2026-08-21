<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Presentation;

use App\Services\Chat\DTO\Blocks\ProductsBlock;
use App\Services\Chat\Presentation\PartsAccumulator;
use Tests\TestCase;

final class PartsAccumulatorTest extends TestCase
{
    public function test_finish_returns_empty_array_when_nothing_was_appended(): void
    {
        $this->assertSame([], (new PartsAccumulator)->finish());
    }

    public function test_text_becomes_a_single_text_part(): void
    {
        $accumulator = new PartsAccumulator;
        $accumulator->appendText('Ось варіанти:');

        $this->assertSame(
            [['type' => 'text', 'text' => 'Ось варіанти:']],
            $accumulator->finish(),
        );
    }

    public function test_consecutive_text_joins_into_one_part(): void
    {
        $accumulator = new PartsAccumulator;
        $accumulator->appendText('Перший рядок.');
        $accumulator->appendText('Другий рядок.');

        $parts = $accumulator->finish();

        $this->assertCount(1, $parts);
        $this->assertSame("Перший рядок.\n\nДругий рядок.", $parts[0]['text']);
    }

    public function test_a_block_cuts_the_prose_into_separate_parts(): void
    {
        $accumulator = new PartsAccumulator;
        $accumulator->appendText('До блоку.');
        $accumulator->pushBlock(new ProductsBlock([]));
        $accumulator->appendText('Після блоку.');

        $parts = $accumulator->finish();

        $this->assertSame(['text', 'products', 'text'], array_column($parts, 'type'));
        $this->assertSame('До блоку.', $parts[0]['text']);
        $this->assertSame('Після блоку.', $parts[2]['text']);
    }

    public function test_blank_prose_produces_no_text_part(): void
    {
        $accumulator = new PartsAccumulator;
        $accumulator->appendText("   \n  ");
        $accumulator->pushBlock(new ProductsBlock([]));

        $this->assertSame(['products'], array_column($accumulator->finish(), 'type'));
    }

    public function test_text_content_excludes_blocks(): void
    {
        $accumulator = new PartsAccumulator;
        $accumulator->appendText('До блоку.');
        $accumulator->pushBlock(new ProductsBlock([]));
        $accumulator->appendText('Після блоку.');
        $accumulator->finish();

        $this->assertSame("До блоку.\n\nПісля блоку.", $accumulator->textContent());
    }
}
