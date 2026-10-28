<?php
declare(strict_types=1);

namespace Tablo;

final readonly class BuildMetadata
{
    public function __construct(public string $repository, public string $branch, public string $sha) {}

    public static function configured(): self
    {
        $repository = getenv('TABLO_BUILD_REPOSITORY'); $branch = getenv('TABLO_BUILD_BRANCH'); $sha = getenv('TABLO_BUILD_SHA');
        return new self($repository === false ? '' : $repository, $branch === false ? '' : $branch, strtolower($sha === false ? '' : $sha));
    }

    public function valid(): bool
    {
        return $this->repository === 'ichinya/tablo' && preg_match('/\A[0-9a-f]{40}\z/D', $this->sha) === 1
            && $this->branch !== '' && strlen($this->branch) <= 200 && preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $this->branch) !== 1
            && !str_contains($this->branch, '..') && !str_contains($this->branch, '@{') && !str_ends_with($this->branch, '.')
            && !str_starts_with($this->branch, '/') && !str_ends_with($this->branch, '/') && !str_contains($this->branch, '//');
    }

    public function fingerprint(): string
    { return hash('sha256', serialize([$this->repository, $this->branch, $this->sha])); }
}
