<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Deliberate malformed-domain controls use an unvalidated, mutable JSON graph. */
final class JsonMutation
{
    public static function document(string $json): \stdClass
    {
        return self::object(json_decode($json, associative: false, depth: 64, flags: JSON_THROW_ON_ERROR));
    }

    /** @param non-empty-list<string|int> $path */
    public static function set(\stdClass $document, array $path, mixed $value): void
    {
        $last = array_pop($path);
        // These are the eight published schema corruption cases in CheckCommandTest.
        if (!in_array($last, ['secret', 'exit_code', 'value', 'status', 'checked_at', 'health', 'reason'], strict: true)) {
            throw new \LogicException('Unsupported schema corruption target');
        }
        self::parent($document, $path, 0)->{$last} = $value;
    }

    /** @param list<string|int> $path */
    private static function parent(mixed $node, array $path, int $offset): \stdClass
    {
        if ($offset === count($path)) { return self::object($node); }
        $key = $path[$offset] ?? throw new \LogicException('Missing corruption path');
        if (is_array($node) && array_key_exists($key, $node)) { return self::parent($node[$key], $path, $offset + 1); }
        if ($node instanceof \stdClass && is_string($key) && property_exists($node, $key)) {
            return self::parent(get_object_vars($node)[$key] ?? null, $path, $offset + 1);
        }
        throw new \LogicException('Missing corruption parent');
    }

    private static function object(mixed $node): \stdClass
    {
        if (!$node instanceof \stdClass) { throw new \LogicException('Corruption parent must be a JSON object'); }
        return $node;
    }
}
