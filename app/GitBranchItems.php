<?php
declare(strict_types=1);

namespace Tablo;

final class GitBranchItems
{
    /** @param array<string, string> $branches */
    public static function add(array $items, array &$branches): void
    {
            if (!array_is_list($items) || count($items) > 100) {
                throw new GitHubFailure('invalid-data');
            }
            foreach (array_keys($items) as $key) {
                $name = GitBranchItem::name($items[$key] ?? null);
                // Prefix keys so a numeric branch name remains a string in JSON.
                $branches['branch:' . $name] = $name;
            }
    }
}
