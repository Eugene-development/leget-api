<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\AST;

/**
 * A JSON scalar type that accepts any valid JSON value.
 */
class JSON extends ScalarType
{
    public string $name = 'JSON';

    public ?string $description = 'Arbitrary JSON data.';

    /**
     * Serialize a PHP value to JSON-compatible output.
     */
    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Parse a client-provided value (from variables).
     */
    public function parseValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Parse a literal AST node from the query string.
     */
    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        return $this->parseLiteralNode($valueNode, $variables);
    }

    private function parseLiteralNode(Node $node, ?array $variables): mixed
    {
        return match (true) {
            $node instanceof StringValueNode  => $node->value,
            $node instanceof IntValueNode     => (int) $node->value,
            $node instanceof FloatValueNode   => (float) $node->value,
            $node instanceof BooleanValueNode => $node->value,
            $node instanceof NullValueNode    => null,
            $node instanceof ListValueNode    => array_map(
                fn (Node $item) => $this->parseLiteralNode($item, $variables),
                iterator_to_array($node->values)
            ),
            $node instanceof ObjectValueNode  => (function () use ($node, $variables): array {
                $obj = [];
                foreach ($node->fields as $field) {
                    $obj[$field->name->value] = $this->parseLiteralNode($field->value, $variables);
                }
                return $obj;
            })(),
            default => throw new Error('Unexpected AST node type: ' . $node->kind),
        };
    }
}
