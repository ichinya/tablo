<?php
declare(strict_types=1);

/** Synthetic response fields shared by the fixture payload and status selectors. */
final class FixtureGitHubResponse
{
    public array $body = [];
    /** @var array<string, string|null> */
    public array $headers = [];

    public function __construct(public int $status) {}
}
