<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class GitCheckResult
{
    /**
     * @param CheckResult $state
     * @param list<string> $errors
     * @param list<string>|null $fieldOrder
     */
    public static function failed(string $field, \Throwable $e, ?array $fieldOrder, #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
                $state['git_outcomes'][$field] = ['status' => 'failed',
                    'reason' => $e instanceof GitHubFailure ? $e->reason : 'unavailable',
                    'http_status' => $e instanceof GitHubFailure ? $e->httpStatus : null,
                    'eligible_at' => $e instanceof GitHubFailure ? $e->eligibleAt : null];
                if (in_array($field, ['latest_release', 'latest_commit'], strict: true)) {
                    $prefix = $field === 'latest_release' ? 'release' : 'commit';
                    $state[$prefix . '_error_code'] = $e instanceof GitHubFailure && in_array($e->reason, CheckHistorySnapshot::GIT_CODES, strict: true)
                        ? $e->reason : 'check-error';
                    $status = $e instanceof GitHubFailure ? $e->httpStatus : null;
                    $state[$prefix . '_http_status'] = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
                }
                $errors[] = $field . ': ' . ($fieldOrder === null || $e instanceof GitHubFailure
                    ? $e->getMessage() : (new GitHubFailure('unavailable'))->getMessage());
    }

}
