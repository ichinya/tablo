<?php
declare(strict_types=1);

namespace Tablo;

final class SiteSaveValidation
{
    public function __construct(private readonly GitHubConnection $github) {}

    /** @param array{git_token_id?: int|null, github_token?: string|null, id?: int, provider?: string, ...}|null $existing */
    public function validate(#[\SensitiveParameter] ?array $existing): ?\Phalcon\Http\Response
    {
        SiteInput::normalize($_POST);
        $snapshot = WebSession::release();
        try {
            $this->github->validate(SiteProjection::withoutEndpointCredential($_POST), SiteProjection::gitCredentials($existing));
        } catch (\Throwable $error) {
            $refusal = WebSession::resume($snapshot);
            if ($refusal !== null) { return $refusal; }
            throw $error;
        }
        return WebSession::resume($snapshot);
    }
}
