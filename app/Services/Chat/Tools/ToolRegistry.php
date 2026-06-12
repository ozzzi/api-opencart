<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Exceptions\Chat\ToolArgumentValidationException;
use App\Exceptions\Chat\ToolNotFoundException;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

/**
 * Registry of all available LLM tools.
 *
 * Responsibilities:
 *  - Provide the OpenAI-format tool definitions for the chat/completions payload.
 *  - Validate tool arguments against the tool's JSON Schema before dispatch.
 *  - Execute the named tool and return its JSON result.
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolInterface> */
    private readonly array $tools;

    /** @param list<ToolInterface> $tools */
    public function __construct(array $tools)
    {
        $indexed = [];

        foreach ($tools as $tool) {
            $indexed[$tool->getName()] = $tool;
        }

        $this->tools = $indexed;
    }

    /**
     * Return the list of tool definitions formatted for OpenAI function calling.
     *
     * @return list<array<string, mixed>>
     */
    public function getOpenAiTools(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $definitions[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters'  => $tool->getParameterSchema(),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Validate arguments against the tool's schema and execute it.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws ToolNotFoundException            when no tool with $name is registered
     * @throws ToolArgumentValidationException  when $args fail schema validation
     */
    public function execute(string $name, array $args, ChatSession $session): string
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            throw new ToolNotFoundException($name);
        }

        $errors = $this->validateArguments($tool->getParameterSchema(), $args);

        if ($errors !== []) {
            throw new ToolArgumentValidationException($name, $errors);
        }

        return $tool->execute($args, $session);
    }

    /**
     * Return whether the named tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Return all registered tool names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    // ── Schema validation ─────────────────────────────────────────────────────

    /**
     * Validate $args against a JSON Schema object.
     *
     * Supported keywords: type, required, properties, items,
     *                     minimum, maximum, minItems, maxItems, enum.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $args
     * @return list<string>  validation error messages (empty = valid)
     */
    private function validateArguments(array $schema, array $args): array
    {
        $errors = [];

        // Check required fields
        foreach ((array) ($schema['required'] ?? []) as $field) {
            if (! array_key_exists($field, $args)) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate present properties
        $properties = (array) ($schema['properties'] ?? []);

        foreach ($properties as $field => $propSchema) {
            if (! array_key_exists($field, $args)) {
                continue;
            }

            $value = $args[$field];
            $propErrors = $this->validateValue($field, $value, (array) $propSchema);

            foreach ($propErrors as $err) {
                $errors[] = $err;
            }
        }

        return $errors;
    }

    /**
     * Validate a single value against a property schema.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateValue(string $field, mixed $value, array $schema): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;

        if ($type !== null) {
            $typeError = $this->checkType($field, $value, (string) $type);

            if ($typeError !== null) {
                return [$typeError];
            }
        }

        // enum
        if (isset($schema['enum'])) {
            if (! in_array($value, (array) $schema['enum'], strict: true)) {
                $allowed = implode(', ', array_map(
                    fn (mixed $v) => is_string($v) ? "\"{$v}\"" : (string) $v,
                    (array) $schema['enum'],
                ));
                $errors[] = "Field [{$field}] must be one of: {$allowed}";
            }
        }

        // numeric constraints
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "Field [{$field}] must be >= {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "Field [{$field}] must be <= {$schema['maximum']}";
            }
        }

        // array constraints
        if (is_array($value)) {
            $count = count($value);

            if (isset($schema['minItems']) && $count < $schema['minItems']) {
                $errors[] = "Field [{$field}] must have at least {$schema['minItems']} items";
            }

            if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
                $errors[] = "Field [{$field}] must have at most {$schema['maxItems']} items";
            }

            // Validate each item if 'items' schema is defined
            if (isset($schema['items']) && is_array($schema['items'])) {
                $itemSchema = (array) $schema['items'];

                foreach ($value as $index => $item) {
                    $itemErrors = $this->validateValue("{$field}[{$index}]", $item, $itemSchema);

                    foreach ($itemErrors as $err) {
                        $errors[] = $err;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Check that $value matches the expected JSON Schema $type.
     * Returns an error message on mismatch, or null on success.
     */
    private function checkType(string $field, mixed $value, string $type): ?string
    {
        $valid = match ($type) {
            'string'  => is_string($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array'   => is_array($value),
            'object'  => is_array($value), // JSON objects arrive as PHP arrays
            'null'    => $value === null,
            default   => true,             // unknown types pass through
        };

        if (! $valid) {
            $actual = gettype($value);

            return "Field [{$field}] must be of type {$type}, got {$actual}";
        }

        return null;
    }
}
