<?php
declare(strict_types=1);

namespace Tablo;

final class GitResolvedCredential
{
    public function __construct(#[\SensitiveParameter] public readonly string $token, #[\SensitiveParameter] public readonly string $revision, public readonly string $scope, public readonly ?string $equivalentScope) {}

}
