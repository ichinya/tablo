<?php
declare(strict_types=1);

namespace Tablo;

final class GitOutput
{

    public static function project(#[\SensitiveParameter] array $state): array
    {
        $fields = []; $completed = 0;
        foreach (CheckOutput::GIT_FIELDS as $field) {
            $fields[$field] = GitFieldOutput::project($field, $state);
            $completed += (int) in_array($fields[$field]['status'], ['ok', 'absent'], strict: true);
        }
        $status = match ($completed) { 4 => 'success', 0 => 'failed', default => 'partial' };
        return ['status' => $status, 'fields' => $fields];
    }

}
