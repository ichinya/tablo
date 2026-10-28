<?php
declare(strict_types=1);

namespace Tablo;

final readonly class GitHubComparison
{
    public function __construct(public string $status, public ?int $ahead) {}

    public static function checked(?array $json): self
    {
        if ($json === null) { return new self('unknown', null); }
        /** @var mixed $status Native API values are narrowed before use. */
        $status = $json['status'] ?? null;
        /** @var mixed $ahead */
        $ahead = $json['ahead_by'] ?? null;
        /** @var mixed $behind */
        $behind = $json['behind_by'] ?? null;
        if (!is_string($status) || !is_int($ahead) || !is_int($behind) || $ahead < 0 || $behind < 0) { throw new GitHubFailure('invalid-data'); }
        $expected = match ($status) { 'ahead' => [true, false], 'behind' => [false, true], 'diverged' => [true, true], 'identical' => [false, false], default => null };
        if ($expected !== [$ahead > 0, $behind > 0]) { throw new GitHubFailure('invalid-data'); }
        return new self($status, $ahead);
    }
}
