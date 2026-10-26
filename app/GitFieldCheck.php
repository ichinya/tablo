<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class GitFieldCheck
{
    public function __construct(private readonly RepositoryProvider $provider) {}
    /**
     * @param CheckResult $state
     * @param list<string> $errors
     * @param \Closure(): (string|int|null) $call
     * @param list<string>|null $fieldOrder
     */
    public function check(string $field, \Closure $call, ?array $fieldOrder, #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): bool
    {
            $before = $this->provider instanceof GitHubProvider ? $this->provider->admissions() : 0;
            $completed = false;
            try {
                $value = $call();
                GitFieldAssignment::write($state, $field, $value);
                // Preserve partial successes while classifying invalid values at their boundary.
                if (!GitFieldValidation::valid($field, $value)) {
                    GitFieldAssignment::write($state, $field, null);
                    throw new GitHubFailure('invalid-data');
                }
                if ($field === 'latest_release') { $state['release_error_code'] = null; }
                if ($field === 'latest_commit') { $state['commit_error_code'] = null; }
                $completed = true;
                $absent = $field === 'latest_release' && $value === null;
                $state['git_outcomes'][$field] = ['status' => $absent ? 'absent' : 'ok',
                    'reason' => $absent ? 'no-release' : 'ok', 'http_status' => null, 'eligible_at' => null];
            } catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                GitCheckResult::failed($field, $e, $fieldOrder, $state, $errors);
            }
            return $completed || ($this->provider instanceof GitHubProvider && $this->provider->admissions() > $before);
    }

}
