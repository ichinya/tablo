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
        return $this->repository === 'ichinya/tablo' && preg_match('/\A[0-9a-f]{40}\z/D', $this->sha) === 1 && $this->validBranch();
    }

    private function validBranch(): bool
    {
        $forbidden = '/[\x00-\x20\x7f~^:?*\[\\\\]|\.\.|@\{|\.\z|\A-/';
        if (in_array($this->branch, ['', '@'], strict: true) || strlen($this->branch) > 200
            || preg_match($forbidden, $this->branch) !== 0) { return false; }
        foreach (explode('/', $this->branch) as $component) {
            if ($component === '' || str_starts_with($component, '.') || str_ends_with($component, '.lock')) { return false; }
        }
        return true;
    }

    public function fingerprint(): string
    { return hash('sha256', serialize([$this->repository, $this->branch, $this->sha])); }
}
