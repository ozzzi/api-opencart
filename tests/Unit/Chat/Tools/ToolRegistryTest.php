<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Exceptions\Chat\ToolArgumentValidationException;
use App\Exceptions\Chat\ToolNotFoundException;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Tools\Contracts\ToolInterface;
use App\Services\Chat\Tools\ToolRegistry;
use Mockery;
use Tests\TestCase;

final class ToolRegistryTest extends TestCase
{
    // ── Registration & lookup ─────────────────────────────────────────────────

    public function test_has_returns_true_for_registered_tool(): void
    {
        $registry = new ToolRegistry([$this->fakeTool('search_products')]);

        $this->assertTrue($registry->has('search_products'));
    }

    public function test_has_returns_false_for_unknown_tool(): void
    {
        $registry = new ToolRegistry([]);

        $this->assertFalse($registry->has('unknown'));
    }

    public function test_names_returns_all_registered_tool_names(): void
    {
        $registry = new ToolRegistry([
            $this->fakeTool('tool_a'),
            $this->fakeTool('tool_b'),
        ]);

        $this->assertEqualsCanonicalizing(['tool_a', 'tool_b'], $registry->names());
    }

    // ── getOpenAiTools ────────────────────────────────────────────────────────

    public function test_get_openai_tools_returns_empty_array_when_no_tools(): void
    {
        $registry = new ToolRegistry([]);

        $this->assertSame([], $registry->getOpenAiTools());
    }

    public function test_get_openai_tools_formats_correctly(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required'   => ['query'],
        ];

        $registry = new ToolRegistry([
            $this->fakeTool('search_kb', $schema, 'Search the knowledge base'),
        ]);

        $tools = $registry->getOpenAiTools();

        $this->assertCount(1, $tools);

        $def = $tools[0];
        $this->assertSame('function', $def['type']);
        $this->assertSame('search_kb', $def['function']['name']);
        $this->assertSame('Search the knowledge base', $def['function']['description']);
        $this->assertSame($schema, $def['function']['parameters']);
    }

    public function test_get_openai_tools_includes_all_tools(): void
    {
        $registry = new ToolRegistry([
            $this->fakeTool('tool_a'),
            $this->fakeTool('tool_b'),
            $this->fakeTool('tool_c'),
        ]);

        $this->assertCount(3, $registry->getOpenAiTools());
    }

    // ── execute – happy path ──────────────────────────────────────────────────

    public function test_execute_returns_tool_result(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required'   => ['query'],
        ];

        $registry = new ToolRegistry([
            $this->fakeTool('search_products', $schema, result: '{"items":[]}'),
        ]);

        $result = $registry->execute('search_products', ['query' => 'laptop'], $this->makeSession());

        $this->assertSame('{"items":[]}', $result);
    }

    public function test_execute_passes_optional_fields_without_error(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'query'     => ['type' => 'string'],
                'price_max' => ['type' => 'number'],
            ],
            'required' => ['query'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('search_products', $schema)]);

        $result = $registry->execute(
            'search_products',
            ['query' => 'laptop', 'price_max' => 30000.0],
            $this->makeSession(),
        );

        $this->assertSame('{"ok":true}', $result);
    }

    // ── execute – ToolNotFoundException ──────────────────────────────────────

    public function test_execute_throws_when_tool_not_registered(): void
    {
        $registry = new ToolRegistry([]);

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessage('ghost');

        $registry->execute('ghost', [], $this->makeSession());
    }

    // ── execute – ToolArgumentValidationException (required) ─────────────────

    public function test_execute_throws_on_missing_required_field(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['product_id' => ['type' => 'integer']],
            'required'   => ['product_id'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('get_product_details', $schema)]);

        $exception = null;

        try {
            $registry->execute('get_product_details', [], $this->makeSession());
        } catch (ToolArgumentValidationException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('product_id', $exception->getMessage());
        $this->assertCount(1, $exception->getErrors());
    }

    // ── execute – type validation ─────────────────────────────────────────────

    public function test_execute_throws_on_wrong_type(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['product_id' => ['type' => 'integer']],
            'required'   => ['product_id'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('get_product_details', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('[product_id]');

        $registry->execute('get_product_details', ['product_id' => 'not-an-int'], $this->makeSession());
    }

    public function test_execute_accepts_integer_for_number_type(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['price' => ['type' => 'number']],
            'required'   => ['price'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('price_tool', $schema)]);

        $result = $registry->execute('price_tool', ['price' => 100], $this->makeSession());

        $this->assertSame('{"ok":true}', $result);
    }

    // ── execute – numeric constraints ─────────────────────────────────────────

    public function test_execute_throws_on_minimum_violation(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
            'required'   => ['limit'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('paginated_tool', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('>= 1');

        $registry->execute('paginated_tool', ['limit' => 0], $this->makeSession());
    }

    public function test_execute_throws_on_maximum_violation(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
            'required'   => ['limit'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('paginated_tool', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('<= 10');

        $registry->execute('paginated_tool', ['limit' => 99], $this->makeSession());
    }

    // ── execute – array constraints ───────────────────────────────────────────

    public function test_execute_throws_on_array_too_short(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'product_ids' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'integer']],
            ],
            'required' => ['product_ids'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('compare_products', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('at least 2');

        $registry->execute('compare_products', ['product_ids' => [1]], $this->makeSession());
    }

    public function test_execute_throws_on_array_too_long(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'product_ids' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'integer']],
            ],
            'required' => ['product_ids'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('compare_products', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('at most 4');

        $registry->execute('compare_products', ['product_ids' => [1, 2, 3, 4, 5]], $this->makeSession());
    }

    public function test_execute_validates_array_item_types(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'product_ids' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'integer']],
            ],
            'required' => ['product_ids'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('compare_products', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('[product_ids[1]]');

        $registry->execute('compare_products', ['product_ids' => [1, 'not-int']], $this->makeSession());
    }

    // ── execute – enum validation ─────────────────────────────────────────────

    public function test_execute_throws_on_enum_violation(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['new', 'contacted', 'closed']],
            ],
            'required' => ['status'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('update_lead', $schema)]);

        $this->expectException(ToolArgumentValidationException::class);
        $this->expectExceptionMessage('[status]');

        $registry->execute('update_lead', ['status' => 'invalid'], $this->makeSession());
    }

    public function test_execute_accepts_valid_enum_value(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['new', 'contacted', 'closed']],
            ],
            'required' => ['status'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('update_lead', $schema)]);

        $result = $registry->execute('update_lead', ['status' => 'new'], $this->makeSession());

        $this->assertSame('{"ok":true}', $result);
    }

    // ── multiple errors ───────────────────────────────────────────────────────

    public function test_execute_collects_multiple_validation_errors(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'name'  => ['type' => 'string'],
                'score' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['name', 'score'],
        ];

        $registry = new ToolRegistry([$this->fakeTool('multi_field', $schema)]);

        $exception = null;

        try {
            $registry->execute('multi_field', [], $this->makeSession());
        } catch (ToolArgumentValidationException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertCount(2, $exception->getErrors());
    }
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a fake tool with the given name and parameter schema.
     *
     * @param  array<string, mixed>  $schema
     */
    private function fakeTool(
        string $name,
        array $schema = [],
        string $description = 'A test tool',
        string $result = '{"ok":true}',
    ): ToolInterface {
        $tool = Mockery::mock(ToolInterface::class);

        $tool->allows('getName')->andReturn($name);
        $tool->allows('getDescription')->andReturn($description);
        $tool->allows('getParameterSchema')->andReturn($schema);
        $tool->allows('execute')->andReturn($result);

        /** @var ToolInterface $tool */
        return $tool;
    }

    private function makeSession(): ChatSession
    {
        return ChatSession::factory()->make();
    }
}
