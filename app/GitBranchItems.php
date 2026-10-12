<?php
declare(strict_types=1);

namespace Tablo;

final class GitBranchItems
{
    public static function add(array $items, array &$branches): void
    {
            if (!array_is_list($items) || count($items) > 100) {
                throw new GitHubFailure('invalid-data');
            }
            foreach ($items as $item) {
                $name = $item['name'] ?? null;
                if (!is_string($name) || $name === '' || strlen($name) > 128 || preg_match('/[\s\x00-\x1f]/', $name)
                    || !preg_match('/^[a-f0-9]{40,64}$/iD', $item['commit']['sha'] ?? '')) {
                    throw new GitHubFailure('invalid-data');
                }
                // Prefix keys so a numeric branch name remains a string in JSON.
                $branches['branch:' . $name] = $name;
            }
    }
}
