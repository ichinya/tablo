<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Leaf array{string, string} */
final class ServerExpressionOperator
{
    private int $depth = 0;
    /** @var array<string, Leaf> */
    private array $leaves = [];

    /** Invocation-local sharing of equal leaves; larger values retain the original allocation path.
     * @internal Only the finite atom/literal producers supply these two-string nodes.
     * @param Leaf $node
     * @return Leaf */
    public function leaf(array $node): array
    {
        return server_expression_leaf($node, $this->leaves);
    }

    /**
     * @param list<string> $tokens
     * @return array
     */
    public function parse(array $tokens, int &$position, int $minimum = 0): array
    {
        if (++$this->depth > 64) { $this->depth--; self::refuse(); }
        $outer = $this->depth === 1;
        try {
        $left = ServerExpressionAtom::parse($tokens, $position, $this);
        while (true) {
            $operator = strtolower($tokens[$position] ?? '');
            $precedence = match ($operator) { 'or' => 10, 'and' => 20, '=', '<=>', '<>', '!=', '<', '>', '<=', '>=', 'is', 'in', 'between' => 30, '+', '-' => 40, '*', '/', 'mod', '%' => 50, default => -1 };
            if ($precedence < $minimum) { break; }
            $position++;
            if ($operator === 'between') {
                $low = $this->parse($tokens, $position, minimum: 31); ServerExpressionTokens::consume($tokens, $position, 'and');
                $left = ['between', $left, $low, $this->parse($tokens, $position, minimum: 31)];
                continue;
            }
            if ($operator === 'in') { $left = $this->membership($tokens, $position, $left); continue; }
            if ($operator === 'is') { $left = server_expression_null_comparison($tokens, $position, $left); continue; }
            $right = $this->parse($tokens, $position, $precedence + 1);
            $left = in_array($operator, ['mod', '%'], strict: true) ? ['call', 'mod', [$left, $right]] : [$operator === '!=' ? '<>' : $operator, $left, $right];
        }
        return $left;
        } finally { $this->depth--; if ($outer) { $this->leaves = []; } }
    }
    /** @param list<string> $tokens */
    private function membership(array $tokens, int &$position, array $left): array
    {
        ServerExpressionTokens::consume($tokens, $position, '('); $list = [];
        do {
            $list[] = $this->parse($tokens, $position, minimum: 31);
            if (($tokens[$position] ?? '') !== ',') { break; }
            $position++;
        } while (true);
        ServerExpressionTokens::consume($tokens, $position, ')');
        return server_expression_membership($left, $list);
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @param list<array> $list */
function server_expression_membership(array $left, array $list): array
{
    if (count($list) === 1) { return ['=', $left, $list[0]]; }
    return ['in', $left, $list];
}

/** @internal Lossless fallback bounds the invocation-owned pool, never accepted SQL.
 * @param array{string, string} $node
 * @param array<string, array{string, string}> $leaves
 * @return array{string, string} */
function server_expression_leaf(array $node, array &$leaves): array
{
    if (strlen($node[1]) > 64) { return $node; }
    $key = $node[0] . "\0" . $node[1];
    if (!array_key_exists($key, $leaves) && count($leaves) >= 32) { return $node; }
    return $leaves[$key] ??= $node;
}

/** @param list<string> $tokens */
function server_expression_null_comparison(array $tokens, int &$position, array $left): array
{
    $not = strtolower($tokens[$position] ?? '') === 'not';
    if ($not) { $position++; }
    ServerExpressionTokens::consume($tokens, $position, 'null');
    return [$not ? 'is not null' : 'is null', $left];
}
