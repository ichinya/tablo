<?php
declare(strict_types=1);

namespace Tablo;

final class GitFieldCheck
{
    public function __construct(private readonly RepositoryProvider $provider) {}
    public function check(string $field, \Closure $call, ?array $fieldOrder, #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): bool
    {
            $before = $this->provider instanceof GitHubProvider ? $this->provider->admissions() : 0;
            $completed = false;
            try {
                $state[$field] = $call();
                // Preserve partial successes while classifying invalid values at their boundary.
                if (!GitFieldValidation::valid($field, $state[$field])) {
                    $state[$field] = null;
                    throw new GitHubFailure('invalid-data');
                }
                if (in_array($field, ['latest_release', 'latest_commit'], strict: true)) {
                    $state[($field === 'latest_release' ? 'release' : 'commit') . '_error_code'] = null;
                }
                $completed = true;
                $absent = $field === 'latest_release' && $state[$field] === null;
                $state['git_outcomes'][$field] = ['status' => $absent ? 'absent' : 'ok',
                    'reason' => $absent ? 'no-release' : 'ok', 'http_status' => null, 'eligible_at' => null];
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                GitCheckResult::failed($field, $e, $fieldOrder, $state, $errors);
            }
            return $completed || ($this->provider instanceof GitHubProvider && $this->provider->admissions() > $before);
    }

}
