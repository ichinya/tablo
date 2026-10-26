<?php
declare(strict_types=1);

namespace Tablo;

final class ServerExpressionOperator
{
    private int $depth = 0;
    /**
     * @param list<string> $tokens
     * @return array
     */
    public function parse(array $tokens, int &$position, int $minimum = 0): array
    {
        if (++$this->depth > 64) { self::refuse(); }
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
            if ($operator === 'is') { $left = self::nullComparison($tokens, $position, $left); continue; }
            $right = $this->parse($tokens, $position, $precedence + 1);
            $left = in_array($operator, ['mod', '%'], strict: true) ? ['call', 'mod', [$left, $right]] : [$operator === '!=' ? '<>' : $operator, $left, $right];
        }
        return $left;
        } finally { $this->depth--; }
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

    /** @param list<string> $tokens */
    private static function nullComparison(array $tokens, int &$position, array $left): array
    {
        $not = strtolower($tokens[$position] ?? '') === 'not';
        if ($not) { $position++; }
        ServerExpressionTokens::consume($tokens, $position, 'null');
        return [$not ? 'is not null' : 'is null', $left];
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @param list<array> $list */
function server_expression_membership(array $left, array $list): array
{
    if (count($list) === 1) { return ['=', $left, $list[0]]; }
    return ['in', $left, $list];
}
